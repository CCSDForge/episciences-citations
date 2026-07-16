<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Tracks OpenAlex's daily bulk-endpoint call budget in a cache, refilling at
 * midnight UTC regardless of the server's local timezone.
 *
 * Extracted from OpenAlexResolver to keep both classes under Sonar's
 * 20-method-per-class limit (S1448).
 */
class OpenAlexQuotaTracker
{
    public function __construct(
        private readonly CacheItemPoolInterface $quotaCache,
        private readonly int $dailyBulkQuota,
    ) {
    }

    public function hasBulkQuotaLeft(): bool
    {
        return $this->getBulkCallsToday() < $this->dailyBulkQuota;
    }

    public function incrementBulkCallsToday(): void
    {
        try {
            $item = $this->quotaCache->getItem($this->quotaCacheKey());
            $item->set($this->getBulkCallsToday() + 1);
            $this->quotaCache->save($item);
        } catch (InvalidArgumentException) {
            // Quota tracking is best-effort; a cache failure must not block resolution.
        }
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

    private function quotaCacheKey(): string
    {
        return 'openalex_bulk_calls_' . new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d');
    }
}
