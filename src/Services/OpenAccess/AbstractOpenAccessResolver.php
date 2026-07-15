<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

/**
 * Base class for resolvers whose underlying API has no bulk lookup:
 * resolveMany() falls back to looping over resolve() one DOI at a time.
 */
abstract class AbstractOpenAccessResolver implements OpenAccessResolverInterface
{
    /**
     * @param array<int, string> $dois
     * @return array<string, OpenAccessResult|null>
     */
    public function resolveMany(array $dois): array
    {
        $results = [];
        foreach ($dois as $doi) {
            $results[$doi] = $this->resolve($doi);
        }

        return $results;
    }

    public function getMaxBatchSize(): int
    {
        return 1;
    }
}
