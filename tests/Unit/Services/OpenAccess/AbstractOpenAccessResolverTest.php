<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OpenAccess;

use App\Services\OpenAccess\AbstractOpenAccessResolver;
use App\Services\OpenAccess\OpenAccessResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared/template logic of AbstractOpenAccessResolver (resolveMany()'s
 * one-by-one fallback and the fixed getMaxBatchSize()) via an anonymous concrete
 * subclass, since the class itself has no HTTP/cache dependency of its own.
 */
class AbstractOpenAccessResolverTest extends TestCase
{
    #[Test]
    public function testResolveMany_CallsResolveOncePerDoi_KeyedByDoi(): void
    {
        // Arrange - a resolver whose resolve() returns a deterministic result per DOI
        $calls = [];
        $resolver = new class ($calls) extends AbstractOpenAccessResolver {
            /** @param array<int, string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function resolve(string $doi): ?OpenAccessResult
            {
                $this->calls[] = $doi;

                return new OpenAccessResult('https://example.org/' . $doi, 'Source for ' . $doi);
            }

            public function getProviderName(): string
            {
                return 'fake-provider';
            }
        };

        // Act
        $results = $resolver->resolveMany(['10.1/a', '10.2/b', '10.3/c']);

        // Assert - resolve() was called once per DOI, in order, and results are keyed by DOI
        $this->assertSame(['10.1/a', '10.2/b', '10.3/c'], $calls);
        $this->assertCount(3, $results);
        $this->assertSame('https://example.org/10.1/a', $results['10.1/a']->url);
        $this->assertSame('Source for 10.2/b', $results['10.2/b']->sourceTitle);
        $this->assertSame('https://example.org/10.3/c', $results['10.3/c']->url);
    }

    #[Test]
    public function testResolveMany_PreservesNullResultsForUnresolvedDois(): void
    {
        // Arrange - a resolver that fails to resolve some DOIs (returns null)
        $resolver = new class extends AbstractOpenAccessResolver {
            public function resolve(string $doi): ?OpenAccessResult
            {
                return $doi === '10.1/found' ? new OpenAccessResult('https://example.org/found') : null;
            }

            public function getProviderName(): string
            {
                return 'fake-provider';
            }
        };

        // Act
        $results = $resolver->resolveMany(['10.1/found', '10.2/missing']);

        // Assert
        $this->assertNotNull($results['10.1/found']);
        $this->assertNull($results['10.2/missing']);
    }

    #[Test]
    public function testResolveMany_WithEmptyDoiList_ReturnsEmptyArray(): void
    {
        // Arrange
        $resolver = new class extends AbstractOpenAccessResolver {
            public function resolve(string $doi): ?OpenAccessResult
            {
                throw new \LogicException('resolve() must not be called for an empty DOI list');
            }

            public function getProviderName(): string
            {
                return 'fake-provider';
            }
        };

        // Act
        $results = $resolver->resolveMany([]);

        // Assert
        $this->assertSame([], $results);
    }

    #[Test]
    public function testGetMaxBatchSize_DefaultsToOne(): void
    {
        // Arrange - the base class hardcodes a batch size of 1 (no bulk endpoint)
        $resolver = new class extends AbstractOpenAccessResolver {
            public function resolve(string $doi): ?OpenAccessResult
            {
                return null;
            }

            public function getProviderName(): string
            {
                return 'fake-provider';
            }
        };

        // Act & Assert
        $this->assertSame(1, $resolver->getMaxBatchSize());
    }
}
