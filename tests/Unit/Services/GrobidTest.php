<?php

namespace App\Tests\Unit\Services;

use App\Entity\PaperReferences;
use App\Services\Grobid;
use App\Services\Tei;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GrobidTest extends TestCase
{
    private Grobid $service;
    private HttpClientInterface $httpClient;
    private Tei $tei;
    private EntityManagerInterface $entityManager;
    private FilesystemAdapter $grobidCache;
    private string $cacheFolder = '/tmp/grobid_cache';
    private string $grobidUrl = 'http://mock-grobid/api/processReferences';

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->tei = $this->createMock(Tei::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Utiliser une vraie instance de cache pour éviter les problèmes avec CacheItem (classe final)
        // C'est acceptable pour un test unitaire car on teste seulement la logique du service
        $this->grobidCache = new FilesystemAdapter('grobid_test', 0, sys_get_temp_dir() . '/test_cache');

        $this->service = new Grobid(
            $this->httpClient,
            $this->tei,
            $this->entityManager,
            $this->cacheFolder,
            $this->grobidUrl,
            $this->grobidCache
        );
    }

    protected function tearDown(): void
    {
        // Nettoyer le cache après chaque test
        $this->grobidCache->clear();
    }

    #[Test]
    public function testInsertReferences_WithCacheHit_UsesCache(): void
    {
        // Arrange
        $docId = 123456;
        $pathPdf = '/tmp/test.pdf';
        $cachedTeiXml = '<TEI>cached response</TEI>';
        $references = [
            json_encode(['raw_reference' => 'Cached reference 1'])
        ];

        // Pré-remplir le cache
        $this->service->putGrobidReferencesInCache($docId . '.pdf', $cachedTeiXml);

        // Mock: Tei parse le XML du cache
        $this->tei->expects($this->once())
            ->method('getReferencesInTei')
            ->with($cachedTeiXml)
            ->willReturn($references);

        // Mock: insertion en DB
        $this->tei->expects($this->once())
            ->method('insertReferencesInDB')
            ->with(
                $references,
                $docId,
                PaperReferences::SOURCE_METADATA_GROBID
            );

        // HTTP client ne doit PAS être appelé (cache hit)
        $this->httpClient->expects($this->never())
            ->method('request');

        // Act
        $result = $this->service->insertReferences($docId, $pathPdf);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testInsertReferences_WithCacheMiss_CallsGrobidAPI(): void
    {
        // Arrange
        $docId = 123456;
        $pathPdf = '/tmp/test.pdf';
        $grobidResponse = '<TEI>API response</TEI>';
        $references = [
            json_encode(['raw_reference' => 'API reference 1'])
        ];

        // Cache est vide (setUp + pas de pré-remplissage)

        // Mock: HTTP request to GROBID API
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($grobidResponse);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->grobidUrl, $this->anything())
            ->willReturn($response);

        // Mock: Tei parse la réponse API
        $this->tei->expects($this->once())
            ->method('getReferencesInTei')
            ->with($grobidResponse)
            ->willReturn($references);

        // Mock: insertion en DB
        $this->tei->expects($this->once())
            ->method('insertReferencesInDB')
            ->with(
                $references,
                $docId,
                PaperReferences::SOURCE_METADATA_GROBID
            );

        // Act
        $result = $this->service->insertReferences($docId, $pathPdf);

        // Assert
        $this->assertTrue($result);

        // Vérifier que la réponse a été mise en cache
        $cachedData = $this->service->getGrobidReferencesInCache($docId . '.pdf');
        $this->assertEquals($grobidResponse, $cachedData);
    }

    #[Test]
    public function testInsertReferences_EmptyReferences_ReturnsFalse(): void
    {
        // Arrange
        $docId = 123456;
        $pathPdf = '/tmp/test.pdf';
        $grobidResponse = '<TEI>empty response</TEI>';

        // Mock: HTTP request
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($grobidResponse);

        $this->httpClient->method('request')
            ->willReturn($response);

        // Mock: Tei retourne tableau vide (aucune référence trouvée)
        $this->tei->expects($this->once())
            ->method('getReferencesInTei')
            ->with($grobidResponse)
            ->willReturn([]);

        // insertReferencesInDB ne doit PAS être appelé
        $this->tei->expects($this->never())
            ->method('insertReferencesInDB');

        // Act
        $result = $this->service->insertReferences($docId, $pathPdf);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testPutGrobidReferencesInCache_NewItem_SavesInCache(): void
    {
        // Arrange
        $name = '123456.pdf';
        $response = '<TEI>test response</TEI>';

        // Act
        $this->service->putGrobidReferencesInCache($name, $response);

        // Assert - vérifier que le contenu est bien en cache
        $cachedData = $this->service->getGrobidReferencesInCache($name);
        $this->assertEquals($response, $cachedData);
    }

    #[Test]
    public function testPutGrobidReferencesInCache_ExistingItem_DoesNotOverwrite(): void
    {
        // Arrange
        $name = '123456.pdf';
        $originalResponse = '<TEI>original response</TEI>';
        $newResponse = '<TEI>new response</TEI>';

        // Pré-remplir le cache
        $this->service->putGrobidReferencesInCache($name, $originalResponse);

        // Act - tenter de remplacer (ne devrait rien faire)
        $this->service->putGrobidReferencesInCache($name, $newResponse);

        // Assert - vérifier que le contenu original est toujours en cache
        $cachedData = $this->service->getGrobidReferencesInCache($name);
        $this->assertEquals($originalResponse, $cachedData);
    }

    #[Test]
    public function testGetGrobidReferencesInCache_ItemExists_ReturnsContent(): void
    {
        // Arrange
        $name = '123456.pdf';
        $cachedContent = '<TEI>cached content</TEI>';

        // Pré-remplir le cache
        $this->service->putGrobidReferencesInCache($name, $cachedContent);

        // Act
        $result = $this->service->getGrobidReferencesInCache($name);

        // Assert
        $this->assertEquals($cachedContent, $result);
    }

    #[Test]
    public function testGetGrobidReferencesInCache_ItemNotExists_ReturnsFalse(): void
    {
        // Arrange
        $name = '123456.pdf';

        // Cache est vide (pas de pré-remplissage)

        // Act
        $result = $this->service->getGrobidReferencesInCache($name);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testGetAllGrobidReferencesFromDB_ReturnsAllReferences(): void
    {
        // Arrange
        $docId = 123456;

        $ref1 = new PaperReferences();
        $ref1->setId(1);
        $ref2 = new PaperReferences();
        $ref2->setId(2);

        $expectedReferences = [$ref1, $ref2];

        $repository = $this->createMock(\App\Repository\PaperReferencesRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['document' => $docId])
            ->willReturn($expectedReferences);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($repository);

        // Act
        $result = $this->service->getAllGrobidReferencesFromDB($docId);

        // Assert
        $this->assertSame($expectedReferences, $result);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function testGetAcceptedReferencesFromDB_ReturnsOnlyAccepted(): void
    {
        // Arrange
        $docId = 123456;

        $acceptedRef = new PaperReferences();
        $acceptedRef->setId(1);
        $acceptedRef->setAccepted(1);

        $expectedReferences = [$acceptedRef];

        $repository = $this->createMock(\App\Repository\PaperReferencesRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(
                ['document' => $docId, 'accepted' => 1],
                ['referenceOrder' => 'ASC']
            )
            ->willReturn($expectedReferences);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($repository);

        // Act
        $result = $this->service->getAcceptedReferencesFromDB($docId);

        // Assert
        $this->assertSame($expectedReferences, $result);
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->getAccepted());
    }
}
