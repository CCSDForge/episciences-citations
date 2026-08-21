<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\PaperReferences;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for EpisciencesController
 *
 * These tests validate the public API endpoint /visualize-citations
 * which returns bibliographic references for Episciences documents.
 *
 * WebTestCase::createClient() forbids booting the kernel more than once per test
 * (see Symfony\Bundle\FrameworkBundle\Test\WebTestCase), so the client is created
 * once in setUp() and reused (with reboot disabled) by every test method, instead
 * of each method calling createClient() itself.
 */
class EpisciencesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        // The container is compiled with DATABASE_URL resolved from the real OS
        // environment (docker-compose exports a MySQL DSN even for APP_ENV=test),
        // which takes precedence over .env.test's sqlite value. Force a fresh
        // in-memory SQLite database for this process before booting the kernel, so
        // this stays fast, hermetic, and independent of any real database's
        // permissions (see ReferenceEditControllerTest for the same workaround).
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        $this->client = static::createClient();
        $this->client->disableReboot();

        // Fresh SQLite in-memory schema for the tests that need real DB access
        // (see docId → References::getReferences() → Grobid repository queries).
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->dropSchema($metadata);
        } catch (\Throwable) {
            // Schema may not exist yet on the very first run.
        }
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->entityManager);
    }

    private function persistDocumentWithReference(int $docId, array $reference, ?int $accepted, int $order = 0): void
    {
        $document = new Document();
        $document->setId($docId);
        $this->entityManager->persist($document);

        $ref = new PaperReferences();
        $ref->setReference($reference);
        $ref->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref->setAccepted($accepted);
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setReferenceOrder($order);
        $ref->setDocument($document);
        $this->entityManager->persist($ref);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function testVisualizeCitations_WithoutUrl_ReturnsBadRequest(): void
    {
        // Arrange
        $client = $this->client;

        // Act - Call API without URL parameter (with valid CORS origin)
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );

        // Assert
        $this->assertResponseStatusCodeSame(400);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $responseData['status']);
        $this->assertEquals('An URL is missing', $responseData['message']);
    }

    #[Test]
    public function testVisualizeCitations_WithInvalidUrl_ReturnsBadRequest(): void
    {
        // Arrange
        $client = $this->client;

        // Act - Call API with invalid URL (no docId extractable) with valid CORS
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://invalid-url.com',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );

        // Assert
        $this->assertResponseStatusCodeSame(400);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $responseData['status']);
        $this->assertEquals('A docid is missing', $responseData['message']);
    }

    #[Test]
    public function testVisualizeCitations_WithNoReferences_ReturnsEmptyResponse(): void
    {
        // Arrange - a document with no PaperReferences at all
        $docId = 555001;
        $document = new Document();
        $document->setId($docId);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = $this->client;

        // Act
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://episciences.org/' . $docId,
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );

        // Assert
        $this->assertResponseStatusCodeSame(200);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $responseData['status']);
        $this->assertEquals('No reference found', $responseData['message']);
    }

    #[Test]
    public function testVisualizeCitations_WithInvalidOrigin_ReturnsForbidden(): void
    {
        // Arrange
        $client = $this->client;

        // Act - Call API with invalid CORS origin
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://episciences.org/test/123',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://malicious-site.com']
        );

        // Assert - should be blocked by CORS
        $this->assertResponseStatusCodeSame(403);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(403, $responseData['status']);
        $this->assertEquals('Forbidden', $responseData['message']);
    }

    #[Test]
    public function testVisualizeCitations_OPTIONSRequest_ReturnsNoContent(): void
    {
        // Arrange
        $client = $this->client;

        // Act - Send OPTIONS preflight request
        $client->request(
            Request::METHOD_OPTIONS,
            '/visualize-citations',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );

        // Assert - should return 204 No Content for OPTIONS
        $this->assertResponseStatusCodeSame(204);

        $response = $client->getResponse();
        $this->assertTrue($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertEquals('https://test.episciences.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertTrue($response->headers->has('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function testVisualizeCitations_ValidRequest_SetsCORSHeaders(): void
    {
        // Arrange - a document with one accepted reference
        $docId = 555002;
        $this->persistDocumentWithReference($docId, ['raw_reference' => 'Doe, J. (2024). Test Article.'], 1);

        $client = $this->client;

        // Act - default (no "all" param) returns only accepted references
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://episciences.org/' . $docId,
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );

        // Assert
        $this->assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        $this->assertTrue($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertEquals('https://test.episciences.org', $response->headers->get('Access-Control-Allow-Origin'));

        $responseData = json_decode($response->getContent(), true);
        $this->assertCount(1, $responseData);
        $reference = array_values($responseData)[0];
        $this->assertSame('Doe, J. (2024). Test Article.', $reference['ref']['raw_reference']);
        $this->assertEquals(1, $reference['isAccepted']);
    }

    #[Test]
    public function testVisualizeCitations_WithAllParameter_AcceptsParameter(): void
    {
        // Arrange - one accepted and one not-yet-accepted reference on the same document
        $docId = 555003;
        $document = new Document();
        $document->setId($docId);
        $this->entityManager->persist($document);

        $acceptedRef = new PaperReferences();
        $acceptedRef->setReference(['raw_reference' => 'Accepted reference']);
        $acceptedRef->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $acceptedRef->setAccepted(1);
        $acceptedRef->setUpdatedAt(new \DateTimeImmutable());
        $acceptedRef->setReferenceOrder(0);
        $acceptedRef->setDocument($document);
        $this->entityManager->persist($acceptedRef);

        $pendingRef = new PaperReferences();
        $pendingRef->setReference(['raw_reference' => 'Pending reference']);
        $pendingRef->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $pendingRef->setAccepted(0);
        $pendingRef->setUpdatedAt(new \DateTimeImmutable());
        $pendingRef->setReferenceOrder(1);
        $pendingRef->setDocument($document);
        $this->entityManager->persist($pendingRef);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = $this->client;

        // Act - without "all": only the accepted reference is returned
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://episciences.org/' . $docId,
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );
        $this->assertResponseStatusCodeSame(200);
        $acceptedOnly = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $acceptedOnly);

        // Act - with all=1: both references are returned
        $client->request(
            Request::METHOD_GET,
            '/visualize-citations?url=https://episciences.org/' . $docId . '&all=1',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://test.episciences.org']
        );
        $this->assertResponseStatusCodeSame(200);
        $all = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(2, $all);
    }
}
