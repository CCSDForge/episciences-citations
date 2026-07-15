<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OpenAccess;

use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use App\Services\OpenAccess\OpenAccessResolverInterface;
use App\Services\OpenAccess\OpenAccessResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class OpenAccessReferenceEnricherTest extends TestCase
{
    private FilesystemAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new FilesystemAdapter('open_access_test', 0, sys_get_temp_dir() . '/test_cache');
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    #[Test]
    public function testDisabledFeatureDoesNotCallResolver(): void
    {
        $resolver = $this->createMock(OpenAccessResolverInterface::class);
        $resolver->expects($this->never())->method('resolveMany');

        $service = $this->createService($resolver, false);
        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test'];

        $this->assertSame($reference, $service->enrichReference($reference));
    }

    #[Test]
    public function testReferenceWithoutDoiIsLeftUnchanged(): void
    {
        $resolver = $this->createMock(OpenAccessResolverInterface::class);
        $resolver->expects($this->never())->method('resolveMany');

        $service = $this->createService($resolver);
        $reference = ['raw_reference' => 'Reference'];

        $this->assertSame($reference, $service->enrichReference($reference));
    }

    #[Test]
    public function testSuccessfulResolutionAddsOpenAccessField(): void
    {
        $resolver = $this->createResolverReturning(['10.1234/test' => new OpenAccessResult('https://oa.example/x', 'Example Repo')]);

        $result = $this->createService($resolver)->enrichReference(['raw_reference' => 'Reference', 'doi' => '10.1234/test']);

        $this->assertSame('https://oa.example/x', $result['open-access']['url']);
        $this->assertSame('Example Repo', $result['open-access']['source_title']);
        $this->assertSame('openalex', $result['open-access']['origin']);
        $this->assertNotNull($result['open-access']['checked_at']);
    }

    /**
     * Defense in depth: even a resolver-provided URL is re-validated before being stored, in
     * case a provider ever returns something other than a clean http(s) location.
     */
    #[Test]
    public function testResolutionWithUnsafeSchemeDoesNotSetOpenAccessField(): void
    {
        $resolver = $this->createResolverReturning(['10.1234/test' => new OpenAccessResult('javascript:alert(1)', 'Example Repo')]);

        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test'];
        $result = $this->createService($resolver)->enrichReference($reference);

        $this->assertArrayNotHasKey('open-access', $result);
    }

    #[Test]
    public function testFailedResolutionKeepsExistingOpenAccessData(): void
    {
        $resolver = $this->createResolverReturning(['10.1234/test' => null]);

        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test', 'open-access' => ['url' => 'https://old/x', 'origin' => 'openalex']];
        $result = $this->createService($resolver)->enrichReference($reference);

        $this->assertSame($reference, $result);
    }

    #[Test]
    public function testManualOriginIsNeverOverwrittenEvenWithForce(): void
    {
        $resolver = $this->createMock(OpenAccessResolverInterface::class);
        $resolver->expects($this->never())->method('resolveMany');

        $reference = [
            'raw_reference' => 'Reference',
            'doi' => '10.1234/test',
            'open-access' => ['url' => 'https://manual/x', 'origin' => 'user'],
        ];

        $result = $this->createService($resolver)->enrichReference($reference, true);

        $this->assertSame($reference, $result);
    }

    #[Test]
    public function testCacheHitAvoidsSecondResolverCall(): void
    {
        $resolver = $this->createMock(OpenAccessResolverInterface::class);
        $resolver->method('getMaxBatchSize')->willReturn(10);
        $resolver->method('getProviderName')->willReturn('openalex');
        $resolver->expects($this->once())
            ->method('resolveMany')
            ->willReturn(['10.1234/test' => new OpenAccessResult('https://oa.example/x')]);

        $service = $this->createService($resolver);
        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test'];

        $service->enrichReference($reference);
        $service->enrichReference($reference);
    }

    #[Test]
    public function testBatchIsChunkedAccordingToMaxBatchSize(): void
    {
        $resolver = $this->createMock(OpenAccessResolverInterface::class);
        $resolver->method('getMaxBatchSize')->willReturn(1);
        $resolver->method('getProviderName')->willReturn('openalex');
        $resolver->expects($this->exactly(2))
            ->method('resolveMany')
            ->willReturnCallback(static fn (array $dois): array => array_fill_keys($dois, new OpenAccessResult('https://oa.example/x')));

        $service = $this->createService($resolver);
        $service->enrichReferences([
            ['raw_reference' => 'A', 'doi' => '10.1/a'],
            ['raw_reference' => 'B', 'doi' => '10.2/b'],
        ]);
    }

    /**
     * @param array<string, OpenAccessResult|null> $resultByDoi
     */
    private function createResolverReturning(array $resultByDoi): OpenAccessResolverInterface
    {
        $resolver = $this->createStub(OpenAccessResolverInterface::class);
        $resolver->method('getMaxBatchSize')->willReturn(10);
        $resolver->method('getProviderName')->willReturn('openalex');
        $resolver->method('resolveMany')->willReturn($resultByDoi);

        return $resolver;
    }

    private function createService(OpenAccessResolverInterface $resolver, bool $enabled = true): OpenAccessReferenceEnricher
    {
        return new OpenAccessReferenceEnricher(
            $resolver,
            $this->cache,
            $this->createStub(LoggerInterface::class),
            $enabled,
        );
    }
}
