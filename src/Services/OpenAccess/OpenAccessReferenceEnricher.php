<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Enriches references with open-access location data, delegating the actual
 * lookup to whichever OpenAccessResolverInterface is wired in - this class
 * knows nothing about OpenAlex or any other specific provider.
 */
class OpenAccessReferenceEnricher
{
    public function __construct(
        private readonly OpenAccessResolverInterface $resolver,
        private readonly CacheItemPoolInterface $openAccessCache,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
    ) {
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    public function enrichReference(array $reference, bool $force = false): array
    {
        return $this->enrichReferences([$reference], $force)[0] ?? $reference;
    }

    /**
     * @param array<int, array<string, mixed>> $references
     * @return array<int, array<string, mixed>>
     */
    public function enrichReferences(array $references, bool $force = false): array
    {
        if (!$this->enabled && !$force) {
            return $references;
        }

        $doiByIndex = [];
        foreach ($references as $index => $reference) {
            if ($this->hasManualOverride($reference)) {
                continue;
            }
            $doi = $this->normalizeDoi($reference['doi'] ?? null);
            if ($doi !== null) {
                $doiByIndex[$index] = $doi;
            }
        }

        if ($doiByIndex === []) {
            return $references;
        }

        $resultByDoi = $this->resolveDois(array_values(array_unique($doiByIndex)));

        foreach ($doiByIndex as $index => $doi) {
            $result = $resultByDoi[$doi] ?? null;
            if ($result !== null) {
                $references[$index] = $this->applyResult($references[$index], $result);
            }
        }

        return $references;
    }

    /**
     * @param array<int, string> $dois
     * @return array<string, OpenAccessResult|null>
     */
    private function resolveDois(array $dois): array
    {
        $resultByDoi = [];
        $doisToResolve = [];

        foreach ($dois as $doi) {
            $cached = $this->getCached($doi);
            if ($cached !== false) {
                $resultByDoi[$doi] = $cached;
            } else {
                $doisToResolve[] = $doi;
            }
        }

        $batchSize = max(1, $this->resolver->getMaxBatchSize());
        foreach (array_chunk($doisToResolve, $batchSize) as $batch) {
            foreach ($this->resolver->resolveMany($batch) as $doi => $result) {
                $resultByDoi[$doi] = $result;
                if ($result !== null) {
                    $this->saveToCache($doi, $result);
                }
            }
        }

        return $resultByDoi;
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function hasManualOverride(array $reference): bool
    {
        return is_array($reference['open-access'] ?? null)
            && ($reference['open-access']['origin'] ?? null) === 'user';
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function applyResult(array $reference, OpenAccessResult $result): array
    {
        $reference['open-access'] = [
            'url' => $result->url,
            'source_title' => $result->sourceTitle,
            'origin' => $this->resolver->getProviderName(),
            'checked_at' => new DateTimeImmutable()->format(DATE_ATOM),
        ];

        return $reference;
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

    private function getCached(string $doi): OpenAccessResult|false
    {
        try {
            $item = $this->openAccessCache->getItem($this->cacheKey($doi));
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Open-access cache read failed', ['message' => $e->getMessage()]);
            return false;
        }

        if (!$item->isHit()) {
            return false;
        }

        $value = $item->get();

        return new OpenAccessResult((string) ($value['url'] ?? ''), (string) ($value['source_title'] ?? ''));
    }

    private function saveToCache(string $doi, OpenAccessResult $result): void
    {
        try {
            $item = $this->openAccessCache->getItem($this->cacheKey($doi));
            $item->set(['url' => $result->url, 'source_title' => $result->sourceTitle]);
            $this->openAccessCache->save($item);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Open-access cache write failed', ['message' => $e->getMessage()]);
        }
    }

    private function cacheKey(string $doi): string
    {
        return sha1($doi) . '_openAccess';
    }
}
