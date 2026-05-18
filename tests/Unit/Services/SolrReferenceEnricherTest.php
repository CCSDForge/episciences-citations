<?php

namespace App\Tests\Unit\Services;

use App\Services\SolrReferenceEnricher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SolrReferenceEnricherTest extends TestCase
{
    #[Test]
    public function testDisabledFeatureDoesNotCallSolr(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects($this->never())->method('request');

        $service = $this->createService($client, false);
        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test'];

        $this->assertSame($reference, $service->enrichReference($reference));
    }

    #[Test]
    public function testSingleDoiFoundAddsUsefulFields(): void
    {
        $service = $this->createServiceWithDocs([
            [
                'doi' => '10.1234/test',
                'detectors' => ['clayFeet'],
                'status' => '-',
                'pubpeerurl' => 'https://pubpeer.example/10.1234/test',
            ],
        ]);

        $result = $service->enrichReference(['raw_reference' => 'Reference', 'doi' => '10.1234/test']);

        $this->assertSame(['clayFeet'], $result['detectors']);
        $this->assertSame('https://pubpeer.example/10.1234/test', $result['pubpeerurl']);
        $this->assertArrayNotHasKey('status', $result);
    }

    #[Test]
    public function testMultipleDoiResultsAreAppliedPerReference(): void
    {
        $service = $this->createServiceWithDocs([
            ['doi' => '10.1/a', 'detectors' => ['first']],
            ['doi' => '10.2/b', 'status' => ['retracted']],
        ]);

        $results = $service->enrichReferences([
            ['raw_reference' => 'A', 'doi' => '10.1/a'],
            ['raw_reference' => 'B', 'doi' => '10.2/b'],
            ['raw_reference' => 'C', 'doi' => '10.3/c', 'status' => ['old']],
        ]);

        $this->assertSame(['first'], $results[0]['detectors']);
        $this->assertArrayNotHasKey('status', $results[0]);
        $this->assertSame(['retracted'], $results[1]['status']);
        $this->assertArrayNotHasKey('detectors', $results[1]);
        $this->assertArrayNotHasKey('status', $results[2]);
    }

    #[Test]
    public function testSolrErrorKeepsExistingMetadata(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willThrowException(new \RuntimeException('Solr unavailable'));

        $service = $this->createService($client);
        $reference = ['raw_reference' => 'Reference', 'doi' => '10.1234/test', 'detectors' => ['old']];

        $this->assertSame($reference, $service->enrichReference($reference));
    }

    #[Test]
    public function testBatchSizeIsCappedAtOneHundred(): void
    {
        $service = $this->createService($this->createStub(HttpClientInterface::class));

        $this->assertSame(100, $service->getEffectiveBatchSize(250));
        $this->assertSame(1, $service->getEffectiveBatchSize(0));
    }

    private function createServiceWithDocs(array $docs): SolrReferenceEnricher
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'response' => [
                'numFound' => count($docs),
                'docs' => $docs,
            ],
        ]);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        return $this->createService($client);
    }

    private function createService(HttpClientInterface $client, bool $enabled = true): SolrReferenceEnricher
    {
        return new SolrReferenceEnricher(
            $client,
            $this->createStub(LoggerInterface::class),
            $enabled,
            'http://mock-solr/solr',
            'ref_pps',
            100
        );
    }
}
