<?php

declare(strict_types=1);

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
    use ReferenceIdFilterTrait;

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
            $this->processBatch($idBatch, $onlyMissing, $dryRun, $force, $batchSize, $output, $stats);
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
     * @param array<int, int|string> $idBatch
     * @param array<string, int> $stats
     */
    private function processBatch(array $idBatch, bool $onlyMissing, bool $dryRun, bool $force, int $batchSize, OutputInterface $output, array &$stats): void
    {
        $paperReferences = $this->entityManager->getRepository(PaperReferences::class)->findBy(['id' => $idBatch]);
        [$processable, $originalReferences] = $this->filterProcessable($paperReferences, $onlyMissing, $stats);

        if ($processable === []) {
            return;
        }

        $enrichedReferences = $this->solrReferenceEnricher->enrichReferences($originalReferences, $force, $batchSize);
        $this->applyEnrichedReferences($processable, $originalReferences, $enrichedReferences, $dryRun, $output, $stats);

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }

    /**
     * @param array<PaperReferences> $paperReferences
     * @param array<string, int> $stats
     * @return array{0: array<PaperReferences>, 1: array<array<string, mixed>>}
     */
    private function filterProcessable(array $paperReferences, bool $onlyMissing, array &$stats): array
    {
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

        return [$processable, $originalReferences];
    }

    /**
     * @param array<PaperReferences> $processable
     * @param array<array<string, mixed>> $originalReferences
     * @param array<array<string, mixed>> $enrichedReferences
     * @param array<string, int> $stats
     */
    private function applyEnrichedReferences(array $processable, array $originalReferences, array $enrichedReferences, bool $dryRun, OutputInterface $output, array &$stats): void
    {
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
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function hasSolrMetadata(array $reference): bool
    {
        return array_any(self::SOLR_FIELDS, fn($field): bool => array_key_exists($field, $reference));
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
