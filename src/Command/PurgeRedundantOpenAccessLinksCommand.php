<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaperReferences;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes 'open-access' data that was auto-resolved (origin: 'openalex') but only duplicates
 * the reference's own DOI link (see OpenAccessReferenceEnricher::isRedundantWithDoiLink()).
 *
 * Historical data cleanup for references enriched before that redundancy check existed.
 * User-provided open-access links (origin: 'user') are never touched.
 */
#[AsCommand(name: 'app:openaccess:purge-redundant-links', description: 'Remove auto-resolved open-access links that duplicate the reference\'s own DOI link')]
class PurgeRedundantOpenAccessLinksCommand extends Command
{
    use ReferenceIdFilterTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OpenAccessReferenceEnricher $openAccessReferenceEnricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('docid', null, InputOption::VALUE_REQUIRED, 'Limit processing to one document id')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit processing to one source: GROBID, USER, BIBTEX, SEMANTICS')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to the database')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of references per batch');
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
        $stats = ['scanned' => 0, 'purged' => 0];

        foreach (array_chunk($referenceIds, $batchSize) as $idBatch) {
            $this->processBatch($idBatch, $dryRun, $output, $stats);
        }

        $output->writeln(sprintf(
            'Open-access redundant link purge: scanned=%d purged=%d batchSize=%d dryRun=%s',
            $stats['scanned'],
            $stats['purged'],
            $batchSize,
            $dryRun ? 'yes' : 'no'
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, int> $idBatch
     * @param array<string, int> $stats
     */
    private function processBatch(array $idBatch, bool $dryRun, OutputInterface $output, array &$stats): void
    {
        $paperReferences = $this->entityManager->getRepository(PaperReferences::class)->findBy(['id' => $idBatch]);
        $changed = false;

        foreach ($paperReferences as $paperReference) {
            $stats['scanned']++;
            $reference = $paperReference->getReference();

            if (!$this->isPurgeableOpenAccessLink($reference)) {
                continue;
            }

            $stats['purged']++;
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    'Purging redundant open-access link for DOI %s (reference id %d)',
                    $reference['doi'],
                    $paperReference->getId()
                ));
            }

            if (!$dryRun) {
                unset($reference['open-access']);
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
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function isPurgeableOpenAccessLink(array $reference): bool
    {
        if (!$this->hasDoi($reference)) {
            return false;
        }

        $openAccess = $reference['open-access'] ?? null;
        if (!is_array($openAccess) || ($openAccess['origin'] ?? null) !== 'openalex') {
            return false;
        }

        return $this->openAccessReferenceEnricher->isRedundantWithDoiLink($reference['doi'], $openAccess['url'] ?? null);
    }
}
