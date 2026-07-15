<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAlexResolver extends AbstractOpenAccessResolver
{
    private const string SELECT_FIELDS = 'title,authorships,open_access,biblio,primary_location,locations,publication_year,best_oa_location,type_crossref';
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $quotaCache,
        private readonly string $apiUrl,
        private readonly string $mailto,
        private readonly string $apiKey,
        private readonly int $dailyBulkQuota,
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

        return $work !== null ? $this->extractOaInfo($work) : null;
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

        if (!$this->hasBulkQuotaLeft()) {
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
        $this->incrementBulkCallsToday();

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
            $doi = $this->normalizeOpenAlexDoi($work['doi'] ?? null);
            if ($doi === null || !array_key_exists($doi, $results)) {
                continue;
            }
            $results[$doi] = $this->extractOaInfo($work);
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

    /**
     * @param array<string, mixed> $work
     */
    private function extractOaInfo(array $work): ?OpenAccessResult
    {
        $primary = is_array($work['primary_location'] ?? null) ? $work['primary_location'] : null;
        $bestOa = is_array($work['best_oa_location'] ?? null) ? $work['best_oa_location'] : null;
        $locations = is_array($work['locations'] ?? null) ? $work['locations'] : [];

        $info = $this->resolveBestOaInfo($primary, $locations, $bestOa);
        if ($info === null || $info['oa_link'] === '') {
            return null;
        }

        return new OpenAccessResult($info['oa_link'], $info['source_title']);
    }

    /**
     * Ports the priority algorithm from the reference implementation:
     * 1. best_oa_location, 2. primary_location if is_oa, 3. first is_oa entry in locations.
     *
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $locations
     * @param array<string, mixed>|null $bestOa
     * @return array{source_title: string, oa_link: string}|null
     */
    private function resolveBestOaInfo(?array $primary, array $locations, ?array $bestOa): ?array
    {
        if ($bestOa !== null && is_array($bestOa['source'] ?? null)) {
            return [
                'source_title' => (string) ($bestOa['source']['display_name'] ?? ''),
                'oa_link' => (string) ($bestOa['landing_page_url'] ?? ''),
            ];
        }

        if ($primary !== null && ($primary['is_oa'] ?? false) === true && is_array($primary['source'] ?? null)) {
            return [
                'source_title' => (string) ($primary['source']['display_name'] ?? ''),
                'oa_link' => (string) ($primary['landing_page_url'] ?? ''),
            ];
        }

        foreach ($locations as $location) {
            if (($location['is_oa'] ?? false) !== true || !is_array($location['source'] ?? null)) {
                continue;
            }

            $sourceTitle = (string) ($location['source']['type'] ?? '') === 'journal'
                ? (string) ($location['source']['display_name'] ?? '')
                : ($this->findJournalNameInLocations($locations) ?: (string) ($location['source']['display_name'] ?? ''));

            return [
                'source_title' => $sourceTitle,
                'oa_link' => (string) ($location['landing_page_url'] ?? ''),
            ];
        }

        return $this->findFirstAlternativeLocation($locations);
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     */
    private function findJournalNameInLocations(array $locations): string
    {
        foreach ($locations as $location) {
            if (!is_array($location['source'] ?? null)) {
                continue;
            }

            $isJournal = (string) ($location['source']['type'] ?? '') === 'journal';
            $isAcceptedPublishedVersion = ($location['version'] ?? null) === 'publishedVersion'
                && ($location['is_accepted'] ?? false) === true
                && ($location['is_published'] ?? false) === true;

            if ($isJournal || $isAcceptedPublishedVersion) {
                return (string) ($location['source']['display_name'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     * @return array{source_title: string, oa_link: string}|null
     */
    private function findFirstAlternativeLocation(array $locations): ?array
    {
        foreach ($locations as $location) {
            if (!is_array($location['source'] ?? null)) {
                continue;
            }

            $sourceTitle = (string) ($location['source']['type'] ?? '') === 'journal'
                ? (string) ($location['source']['display_name'] ?? '')
                : ($this->findJournalNameInLocations($locations) ?: (string) ($location['source']['display_name'] ?? ''));

            $oaLink = ($location['is_oa'] ?? false) === true
                ? (string) ($location['source']['landing_page_url'] ?? '')
                : '';

            return ['source_title' => $sourceTitle, 'oa_link' => $oaLink];
        }

        return null;
    }

    private function normalizeOpenAlexDoi(mixed $doi): ?string
    {
        if (!is_string($doi) || trim($doi) === '') {
            return null;
        }

        $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', trim($doi)) ?? $doi;

        return strtolower(rawurldecode($doi));
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

    private function hasBulkQuotaLeft(): bool
    {
        return $this->getBulkCallsToday() < $this->dailyBulkQuota;
    }

    private function getBulkCallsToday(): int
    {
        try {
            $item = $this->quotaCache->getItem($this->quotaCacheKey());
            return $item->isHit() ? (int) $item->get() : 0;
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    private function incrementBulkCallsToday(): void
    {
        try {
            $item = $this->quotaCache->getItem($this->quotaCacheKey());
            $item->set($this->getBulkCallsToday() + 1);
            $this->quotaCache->save($item);
        } catch (InvalidArgumentException) {
            // Quota tracking is best-effort; a cache failure must not block resolution.
        }
    }

    /**
     * OpenAlex's daily bulk budget refills at midnight UTC, so the cache key must
     * roll over on the same boundary regardless of the server's local timezone.
     */
    private function quotaCacheKey(): string
    {
        return 'openalex_bulk_calls_' . new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d');
    }
}
