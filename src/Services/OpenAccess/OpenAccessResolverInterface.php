<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

interface OpenAccessResolverInterface
{
    /**
     * Resolves the best open-access location for a single DOI.
     *
     * @return OpenAccessResult|null null when no open-access location was found or the lookup failed
     */
    public function resolve(string $doi): ?OpenAccessResult;

    /**
     * Resolves the best open-access location for several DOIs at once.
     *
     * @param array<int, string> $dois
     * @return array<string, OpenAccessResult|null> result keyed by the input DOI
     */
    public function resolveMany(array $dois): array;

    /**
     * Maximum number of DOIs this resolver can process in a single resolveMany() call.
     */
    public function getMaxBatchSize(): int;

    /**
     * Short provider identifier stored in reference['open-access']['origin'] (e.g. 'openalex').
     */
    public function getProviderName(): string;
}
