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

#[AsCommand(name: 'app:openaccess:enrich-references', description: 'Check/refresh open-access location for existing references by DOI')]
class OpenAccessEnrichReferencesCommand extends Command
{
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
            ->addOption('only-missing', null, InputOption::VALUE_NONE, 'Only process references without open-access metadata')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to the database')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of references per batch')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when automatic open-access resolution is disabled');
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
        $force = (bool) $input->getOption('force');
        $onlyMissing = (bool) $input->getOption('only-missing');

        $referenceIds = $this->getReferenceIds($input);
        $stats = [
            'scanned' => 0,
            'processed' => 0,
            'foundOa' => 0,
            'noOa' => 0,
            'skippedManual' => 0,
            'unchanged' => 0,
        ];

        foreach (array_chunk($referenceIds, $batchSize) as $idBatch) {
            $paperReferences = $this->entityManager->getRepository(PaperReferences::class)->findBy(['id' => $idBatch]);
            $processable = [];
            $originalReferences = [];

            foreach ($paperReferences as $paperReference) {
                $stats['scanned']++;
                $reference = $paperReference->getReference();
                if (!$this->hasDoi($reference)) {
                    continue;
                }
                if ($this->isManuallyProvided($reference)) {
                    $stats['skippedManual']++;
                    continue;
                }
                if ($onlyMissing && $this->hasOpenAccessMetadata($reference)) {
                    continue;
                }

                $processable[] = $paperReference;
                $originalReferences[] = $reference;
            }

            if ($processable === []) {
                continue;
            }

            $enrichedReferences = $this->openAccessReferenceEnricher->enrichReferences($originalReferences, $force);

            foreach ($processable as $index => $paperReference) {
                $stats['processed']++;
                $before = $originalReferences[$index];
                $after = $enrichedReferences[$index] ?? $before;
                $this->classifyChange($before, $after, $stats);

                if ($after !== $before && $output->isVerbose()) {
                    $output->writeln(sprintf(
                        'Resolved open access for DOI %s in document %s',
                        $before['doi'],
                        $paperReference->getDocument()?->getId() ?? 'unknown'
                    ));
                }

                if (!$dryRun && $after !== $before) {
                    $paperReference->setReference($after);
                    $paperReference->setUpdatedAt(new DateTimeImmutable());
                    $this->entityManager->persist($paperReference);
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $output->writeln(sprintf(
            'Open-access enrichment: scanned=%d processed=%d foundOa=%d noOa=%d skippedManual=%d unchanged=%d batchSize=%d dryRun=%s',
            $stats['scanned'],
            $stats['processed'],
            $stats['foundOa'],
            $stats['noOa'],
            $stats['skippedManual'],
            $stats['unchanged'],
            $batchSize,
            $dryRun ? 'yes' : 'no'
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function getReferenceIds(InputInterface $input): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(PaperReferences::class, 'p')
            ->orderBy('p.id', 'ASC');

        if ($input->getOption('docid') !== null) {
            $queryBuilder
                ->andWhere('p.document = :docId')
                ->setParameter('docId', (int) $input->getOption('docid'));
        }

        if ($input->getOption('source') !== null) {
            $queryBuilder
                ->andWhere('p.source = :source')
                ->setParameter('source', $input->getOption('source'));
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $queryBuilder->getQuery()->getArrayResult()
        );
    }

    private function isValidSource(string $source): bool
    {
        return in_array($source, [
            PaperReferences::SOURCE_METADATA_GROBID,
            PaperReferences::SOURCE_METADATA_EPI_USER,
            PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT,
            PaperReferences::SOURCE_SEMANTICS_SCHOLAR,
        ], true);
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function hasDoi(array $reference): bool
    {
        return isset($reference['doi']) && is_string($reference['doi']) && trim($reference['doi']) !== '';
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function isManuallyProvided(array $reference): bool
    {
        return is_array($reference['open-access'] ?? null) && ($reference['open-access']['origin'] ?? null) === 'user';
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function hasOpenAccessMetadata(array $reference): bool
    {
        return is_array($reference['open-access'] ?? null) && ($reference['open-access']['url'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, int> $stats
     */
    private function classifyChange(array $before, array $after, array &$stats): void
    {
        if ($before === $after) {
            $stats['unchanged']++;
        }

        if ($this->hasOpenAccessMetadata($after)) {
            $stats['foundOa']++;
        } else {
            $stats['noOa']++;
        }
    }
}
