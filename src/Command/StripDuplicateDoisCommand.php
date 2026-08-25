<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaperReferences;
use App\Services\Doi;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Strips redundant trailing DOI URLs from stored raw_reference strings
 * when a structured DOI exists for the reference.
 */
#[AsCommand(name: 'app:references:strip-duplicate-dois', description: 'Strip redundant trailing DOI URLs from stored raw_reference in paper references')]
class StripDuplicateDoisCommand extends Command
{
    use ReferenceIdFilterTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('docid', null, InputOption::VALUE_REQUIRED, 'Limit processing to one document id')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit processing to one source: GROBID, USER, BIBTEX, SEMANTICS')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to the database')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of references per batch', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        if (is_string($source) && !$this->isValidSource($source)) {
            $output->writeln('<error>Invalid source. Expected one of: GROBID, USER, BIBTEX, SEMANTICS.</error>');
            return Command::INVALID;
        }

        $batchSize = is_numeric($input->getOption('batch-size')) ? max(1, (int) $input->getOption('batch-size')) : 50;
        $dryRun = (bool) $input->getOption('dry-run');

        $referenceIds = $this->getReferenceIds($input);
        $stats = ['scanned' => 0, 'stripped' => 0];

        foreach (array_chunk($referenceIds, $batchSize) as $idBatch) {
            $batchStats = $this->processBatch($idBatch, $dryRun, $output);
            $stats['scanned'] += $batchStats['scanned'];
            $stats['stripped'] += $batchStats['stripped'];
        }

        $output->writeln(sprintf(
            'Duplicate DOI strip: scanned=%d stripped=%d batchSize=%d dryRun=%s',
            $stats['scanned'],
            $stats['stripped'],
            $batchSize,
            $dryRun ? 'yes' : 'no'
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, int> $idBatch
     * @return array{scanned: int, stripped: int}
     */
    private function processBatch(array $idBatch, bool $dryRun, OutputInterface $output): array
    {
        $paperReferences = $this->entityManager->getRepository(PaperReferences::class)->findBy(['id' => $idBatch]);
        $stats = ['scanned' => 0, 'stripped' => 0];
        $changed = false;

        foreach ($paperReferences as $paperReference) {
            $stats['scanned']++;
            $reference = $paperReference->getReference();

            if (!isset($reference['raw_reference']) || !is_string($reference['raw_reference'])) {
                continue;
            }

            // Only strip against a known structured DOI: without one, the general
            // pattern would delete DOI-looking text with no way to recover it later.
            if (!$this->hasDoi($reference)) {
                continue;
            }

            $doi = (string) $reference['doi'];
            $cleanedRawRef = Doi::stripTrailingDoi($reference['raw_reference'], $doi);

            if ($cleanedRawRef === $reference['raw_reference']) {
                continue;
            }

            $stats['stripped']++;
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    'Stripping trailing DOI for reference id %d (DOI: %s)',
                    $paperReference->getId(),
                    $doi
                ));
            }

            if (!$dryRun) {
                $reference['raw_reference'] = $cleanedRawRef;
                $paperReference->setReference($reference);
                $paperReference->setUpdatedAt(new DateTimeImmutable());
                $this->entityManager->persist($paperReference);
                $changed = true;
            }
        }

        if ($changed) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return $stats;
    }
}
