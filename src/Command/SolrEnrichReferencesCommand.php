<?php

namespace App\Command;

use App\Entity\PaperReferences;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:solr:enrich-references', description: 'Enrich existing references with Solr metadata by DOI')]
class SolrEnrichReferencesCommand extends Command
{
    private const array SOLR_FIELDS = ['detectors', 'status', 'pubpeerurl'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SolrReferenceEnricher $solrReferenceEnricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('docid', null, InputOption::VALUE_REQUIRED, 'Limit processing to one document id')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit processing to one source: GROBID, USER, BIBTEX, SEMANTICS')
            ->addOption('only-missing', null, InputOption::VALUE_NONE, 'Only process references without Solr metadata')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to the database')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of DOI terms per Solr request, capped at 100')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when automatic Solr enrichment is disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        if (is_string($source) && !$this->isValidSource($source)) {
            $output->writeln('<error>Invalid source. Expected one of: GROBID, USER, BIBTEX, SEMANTICS.</error>');
            return Command::INVALID;
        }

        $batchSize = $this->solrReferenceEnricher->getEffectiveBatchSize(
            is_numeric($input->getOption('batch-size')) ? (int) $input->getOption('batch-size') : null
        );
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $onlyMissing = (bool) $input->getOption('only-missing');

        $referenceIds = $this->getReferenceIds($input);
        $stats = [
            'scanned' => 0,
            'processed' => 0,
            'enriched' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'failed' => 0,
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
                if ($onlyMissing && $this->hasSolrMetadata($reference)) {
                    continue;
                }

                $processable[] = $paperReference;
                $originalReferences[] = $reference;
            }

            if ($processable === []) {
                continue;
            }

            $enrichedReferences = $this->solrReferenceEnricher->enrichReferences($originalReferences, $force, $batchSize);

            foreach ($processable as $index => $paperReference) {
                $stats['processed']++;
                $before = $originalReferences[$index];
                $after = $enrichedReferences[$index] ?? $before;
                $change = $this->classifyChange($before, $after, $stats);
                if ($change === 'enriched' && $output->isVerbose()) {
                    $output->writeln(sprintf(
                        'Enriched DOI %s in document %s',
                        $before['doi'],
                        $paperReference->getDocument()?->getId() ?? 'unknown'
                    ));
                }

                if (!$dryRun) {
                    $paperReference->setReference($after);
                    $this->entityManager->persist($paperReference);
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $output->writeln(sprintf(
            'Solr enrichment: scanned=%d processed=%d enriched=%d cleared=%d unchanged=%d failed=%d batchSize=%d dryRun=%s',
            $stats['scanned'],
            $stats['processed'],
            $stats['enriched'],
            $stats['cleared'],
            $stats['unchanged'],
            $stats['failed'],
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
    private function hasSolrMetadata(array $reference): bool
    {
        foreach (self::SOLR_FIELDS as $field) {
            if (array_key_exists($field, $reference)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, int> $stats
     */
    private function classifyChange(array $before, array $after, array &$stats): string
    {
        if ($before === $after) {
            $stats['unchanged']++;
            return 'unchanged';
        }

        if ($this->hasSolrMetadata($after)) {
            $stats['enriched']++;
            return 'enriched';
        }

        $stats['cleared']++;
        return 'cleared';
    }
}
