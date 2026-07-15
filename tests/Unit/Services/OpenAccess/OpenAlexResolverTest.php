<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OpenAccess;

use App\Services\OpenAccess\OpenAlexResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OpenAlexResolverTest extends TestCase
{
    #[Test]
    public function testResolveUsesBestOaLocationPriority(): void
    {
        $resolver = $this->createResolver($this->stubClient([
            'best_oa_location' => ['source' => ['display_name' => 'Zenodo'], 'landing_page_url' => 'https://zenodo.org/x'],
            'primary_location' => null,
            'locations' => [],
        ]));

        $result = $resolver->resolve('10.1234/test');

        $this->assertNotNull($result);
        $this->assertSame('https://zenodo.org/x', $result->url);
        $this->assertSame('Zenodo', $result->sourceTitle);
    }

    #[Test]
    public function testResolveFallsBackToPrimaryLocationWhenOa(): void
    {
        $resolver = $this->createResolver($this->stubClient([
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => true, 'source' => ['display_name' => 'HAL'], 'landing_page_url' => 'https://hal.science/x'],
            'locations' => [],
        ]));

        $result = $resolver->resolve('10.1234/test');

        $this->assertNotNull($result);
        $this->assertSame('https://hal.science/x', $result->url);
        $this->assertSame('HAL', $result->sourceTitle);
    }

    #[Test]
    public function testResolveFallsBackToFirstOaLocationInList(): void
    {
        $resolver = $this->createResolver($this->stubClient([
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false, 'source' => ['display_name' => 'Publisher']],
            'locations' => [
                ['is_oa' => false, 'source' => ['display_name' => 'Closed repo']],
                ['is_oa' => true, 'source' => ['type' => 'journal', 'display_name' => 'Open Journal'], 'landing_page_url' => 'https://oj.example/x'],
            ],
        ]));

        $result = $resolver->resolve('10.1234/test');

        $this->assertNotNull($result);
        $this->assertSame('https://oj.example/x', $result->url);
        $this->assertSame('Open Journal', $result->sourceTitle);
    }

    #[Test]
    public function testResolveReturnsNullWhenNoOaLocationFound(): void
    {
        $resolver = $this->createResolver($this->stubClient([
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false, 'source' => ['display_name' => 'Publisher']],
            'locations' => [],
        ]));

        $this->assertNull($resolver->resolve('10.1234/test'));
    }

    #[Test]
    public function testResolveReturnsNullOnNotFoundStatus(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('toArray')->willReturn([]);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $this->assertNull($this->createResolver($client)->resolve('10.1234/missing'));
    }

    #[Test]
    public function testResolveReturnsNullOnTransportError(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willThrowException(new TransportException('network down'));

        $this->assertNull($this->createResolver($client)->resolve('10.1234/test'));
    }

    #[Test]
    public function testResolveManyUsesBulkEndpointAndMapsResultsByDoi(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'results' => [
                [
                    'doi' => 'https://doi.org/10.1/a',
                    'best_oa_location' => ['source' => ['display_name' => 'S1'], 'landing_page_url' => 'https://s1/a'],
                ],
                [
                    'doi' => 'https://doi.org/10.2/b',
                    'best_oa_location' => null,
                    'primary_location' => null,
                    'locations' => [],
                ],
            ],
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('filter=doi:10.1%2Fa|10.2%2Fb|10.3%2Fc'))
            ->willReturn($response);

        $results = $this->createResolver($client)->resolveMany(['10.1/a', '10.2/b', '10.3/c']);

        $this->assertSame('https://s1/a', $results['10.1/a']->url);
        $this->assertNull($results['10.2/b']);
        $this->assertNull($results['10.3/c']);
    }

    #[Test]
    public function testResolveManyFallsBackToSingletonCallsWhenDailyBulkQuotaIsExhausted(): void
    {
        $quotaCache = new ArrayAdapter();
        $item = $quotaCache->getItem('openalex_bulk_calls_' . new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d'));
        $item->set(10);
        $quotaCache->save($item);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'best_oa_location' => ['source' => ['display_name' => 'S1'], 'landing_page_url' => 'https://s1/x'],
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('request')
            ->with('GET', $this->logicalAnd(
                $this->stringContains('/works/https://doi.org/'),
                $this->logicalNot($this->stringContains('filter='))
            ))
            ->willReturn($response);

        $results = $this->createResolver($client, $quotaCache, 10)->resolveMany(['10.1/a', '10.2/b']);

        $this->assertSame('https://s1/x', $results['10.1/a']->url);
        $this->assertSame('https://s1/x', $results['10.2/b']->url);
    }

    #[Test]
    public function testGetMaxBatchSizeIsOneHundred(): void
    {
        $this->assertSame(100, $this->createResolver($this->createStub(HttpClientInterface::class))->getMaxBatchSize());
    }

    #[Test]
    public function testGetProviderNameIsOpenalex(): void
    {
        $this->assertSame('openalex', $this->createResolver($this->createStub(HttpClientInterface::class))->getProviderName());
    }

    /**
     * @param array<string, mixed> $work
     */
    private function stubClient(array $work): HttpClientInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($work);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        return $client;
    }

    private function createResolver(HttpClientInterface $client, ?ArrayAdapter $quotaCache = null, int $dailyBulkQuota = 10000): OpenAlexResolver
    {
        return new OpenAlexResolver(
            $client,
            $this->createStub(LoggerInterface::class),
            $quotaCache ?? new ArrayAdapter(),
            'https://api.openalex.org/works/',
            'test@example.org',
            'test-api-key',
            $dailyBulkQuota
        );
    }
}
