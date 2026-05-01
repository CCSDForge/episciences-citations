<?php

namespace App\Services;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SolrReferenceEnricher
{
    private const array SOLR_FIELDS = ['detectors', 'status', 'pubpeerurl'];
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $solrBaseUrl,
        private readonly string $collection,
        private readonly int $batchSize,
    ) {
    }

    public function enrichReference(array $reference, bool $force = false): array
    {
        return $this->enrichReferences([$reference], $force)[0] ?? $reference;
    }

    /**
     * @param array<int, array<string, mixed>> $references
     * @return array<int, array<string, mixed>>
     */
    public function enrichReferences(array $references, bool $force = false, ?int $batchSize = null): array
    {
        if (!$this->enabled && !$force) {
            return $references;
        }

        $doiByIndex = [];
        foreach ($references as $index => $reference) {
            $doi = $this->normalizeDoi($reference['doi'] ?? null);
            if ($doi === null) {
                $references[$index] = $this->clearSolrFields($reference);
                continue;
            }
            $doiByIndex[$index] = $doi;
        }

        if ($doiByIndex === []) {
            return $references;
        }

        $metadataByDoi = [];
        $failedDoi = [];
        $effectiveBatchSize = $this->getEffectiveBatchSize($batchSize);
        foreach (array_chunk(array_values(array_unique($doiByIndex)), $effectiveBatchSize) as $doiBatch) {
            $batchMetadata = $this->fetchMetadataByDoi($doiBatch);
            if ($batchMetadata === null) {
                foreach ($doiBatch as $doi) {
                    $failedDoi[$doi] = true;
                }
                continue;
            }
            $metadataByDoi += $batchMetadata;
        }

        foreach ($doiByIndex as $index => $doi) {
            if (isset($failedDoi[$doi])) {
                continue;
            }
            if (!array_key_exists($doi, $metadataByDoi)) {
                $references[$index] = $this->clearSolrFields($references[$index]);
                continue;
            }

            $references[$index] = $this->applyMetadata($this->clearSolrFields($references[$index]), $metadataByDoi[$doi]);
        }

        return $references;
    }

    public function getEffectiveBatchSize(?int $batchSize = null): int
    {
        $size = $batchSize ?? $this->batchSize;
        return max(1, min(self::MAX_BATCH_SIZE, $size));
    }

    private function normalizeDoi(mixed $doi): ?string
    {
        if (!is_string($doi)) {
            return null;
        }

        $doi = trim($doi);
        if ($doi === '') {
            return null;
        }

        $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', $doi) ?? $doi;
        return strtolower(rawurldecode($doi));
    }

    /**
     * @param array<int, string> $dois
     * @return array<string, array<string, mixed>>|null
     */
    private function fetchMetadataByDoi(array $dois): ?array
    {
        try {
            $response = $this->client->request('GET', $this->getSelectUrl(), [
                'query' => [
                    'indent' => 'false',
                    'q.op' => 'OR',
                    'q' => $this->buildDoiQuery($dois),
                ],
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Solr reference enrichment failed', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return null;
        }

        $metadataByDoi = [];
        foreach ($data['response']['docs'] ?? [] as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $doi = $this->normalizeDoi($doc['doi'] ?? null);
            if ($doi === null) {
                continue;
            }
            $metadataByDoi[$doi] = $doc;
        }

        return $metadataByDoi;
    }

    /**
     * @param array<int, string> $dois
     */
    private function buildDoiQuery(array $dois): string
    {
        if (count($dois) === 1) {
            return 'doi:' . $this->escapeSolrTerm($dois[0]);
        }

        return implode(' OR ', array_map(
            fn (string $doi): string => 'doi:' . $this->escapeSolrTerm($doi),
            $dois
        ));
    }

    private function escapeSolrTerm(string $term): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $term) . '"';
    }

    private function getSelectUrl(): string
    {
        return rtrim($this->solrBaseUrl, '/') . '/' . trim($this->collection, '/') . '/select';
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function clearSolrFields(array $reference): array
    {
        foreach (self::SOLR_FIELDS as $field) {
            unset($reference[$field]);
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function applyMetadata(array $reference, array $metadata): array
    {
        foreach (self::SOLR_FIELDS as $field) {
            $value = $this->cleanValue($metadata[$field] ?? null);
            if ($value !== null) {
                $reference[$field] = $value;
            }
        }

        return $reference;
    }

    private function cleanValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $filtered = array_values(array_filter(
                $value,
                static fn (mixed $item): bool => is_string($item) ? trim($item) !== '' && trim($item) !== '-' : $item !== null
            ));

            return $filtered === [] ? null : $filtered;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' || $value === '-' ? null : $value;
        }

        return $value;
    }
}
