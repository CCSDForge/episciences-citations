<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves open-access location info from the OpenAlex API.
 *
 * Payload parsing lives in OpenAlexWorkParser and daily bulk-quota tracking in
 * OpenAlexQuotaTracker — split out to keep every class under Sonar's
 * 20-method-per-class limit (S1448).
 */
class OpenAlexResolver extends AbstractOpenAccessResolver
{
    private const string SELECT_FIELDS = 'doi,primary_location,locations,best_oa_location';
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly OpenAlexQuotaTracker $quotaTracker,
        private readonly OpenAlexWorkParser $workParser,
        private readonly string $apiUrl,
        private readonly string $mailto,
        private readonly string $apiKey,
    ) {
    }

    public function resolve(string $doi): ?OpenAccessResult
    {
        $this->throttle();

        $url = rtrim($this->apiUrl, '/') . '/https://doi.org/' . rawurlencode($doi)
            . '?select=' . self::SELECT_FIELDS
            . '&mailto=' . rawurlencode($this->mailto)
            . $this->apiKeyParam();

        $work = $this->requestJson($url, $doi);

        return $work !== null ? $this->workParser->extractOaInfo($work) : null;
    }

    /**
     * @param array<int, string> $dois
     * @return array<string, OpenAccessResult|null>
     */
    public function resolveMany(array $dois): array
    {
        if ($dois === []) {
            return [];
        }

        if (!$this->quotaTracker->hasBulkQuotaLeft()) {
            $this->logger->warning('OpenAlex daily bulk quota reached, falling back to singleton calls');
            return parent::resolveMany($dois);
        }

        return $this->resolveManyViaBulkEndpoint($dois);
    }

    public function getMaxBatchSize(): int
    {
        return self::MAX_BATCH_SIZE;
    }

    public function getProviderName(): string
    {
        return 'openalex';
    }

    /**
     * @param array<int, string> $dois
     * @return array<string, OpenAccessResult|null>
     */
    private function resolveManyViaBulkEndpoint(array $dois): array
    {
        $this->throttle();
        $this->quotaTracker->incrementBulkCallsToday();

        $url = rtrim($this->apiUrl, '/')
            . '?filter=doi:' . implode('|', array_map(rawurlencode(...), $dois))
            . '&select=' . self::SELECT_FIELDS
            . '&per_page=' . self::MAX_BATCH_SIZE
            . '&mailto=' . rawurlencode($this->mailto)
            . $this->apiKeyParam();

        $data = $this->requestJson($url, implode(',', $dois));

        $results = array_fill_keys($dois, null);
        foreach ($data['results'] ?? [] as $work) {
            if (!is_array($work)) {
                continue;
            }
            $doi = $this->workParser->normalizeDoi($work['doi'] ?? null);
            if ($doi === null || !array_key_exists($doi, $results)) {
                continue;
            }
            $results[$doi] = $this->workParser->extractOaInfo($work);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestJson(string $url, string $context): ?array
    {
        try {
            $response = $this->client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('OpenAlex API request failed', ['doi' => $context, 'message' => $e->getMessage()]);
            return null;
        }

        if ($statusCode !== 200) {
            return null;
        }

        return $data;
    }

    private function apiKeyParam(): string
    {
        return $this->apiKey !== '' ? '&api_key=' . rawurlencode($this->apiKey) : '';
    }

    /**
     * Free/polite-pool tier is rate-limited; API key tier is not throttled client-side.
     */
    private function throttle(): void
    {
        if ($this->apiKey === '') {
            usleep(500000);
        }
    }
}
