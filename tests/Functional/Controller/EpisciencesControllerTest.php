<?php

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for EpisciencesController
 *
 * These tests validate the public API endpoint /visualize-citations
 * which returns bibliographic references for Episciences documents.
 *
 * Note: These are basic validation tests. Full integration tests with database
 * would require more complex setup with fixtures and are better suited for
 * the Integration test suite.
 */
class EpisciencesControllerTest extends WebTestCase
{
    #[Test]
    public function testVisualizeCitations_WithoutUrl_ReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        // Act - Call API without URL parameter (with valid CORS origin)
        $client->request(
            'GET',
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
        $client = static::createClient();

        // Act - Call API with invalid URL (no docId extractable) with valid CORS
        $client->request(
            'GET',
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

    /**
     * Note: This test is skipped because it requires database access.
     * The database configuration issue needs to be resolved in the test environment.
     * TODO: Fix database configuration to use SQLite in-memory for functional tests
     */
    #[Test]
    public function testVisualizeCitations_WithNoReferences_ReturnsEmptyResponse(): void
    {
        $this->markTestSkipped('Database access issue - requires SQLite in-memory configuration');
    }

    #[Test]
    public function testVisualizeCitations_WithInvalidOrigin_ReturnsForbidden(): void
    {
        // Arrange
        $client = static::createClient();

        // Act - Call API with invalid CORS origin
        $client->request(
            'GET',
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
        $client = static::createClient();

        // Act - Send OPTIONS preflight request
        $client->request(
            'OPTIONS',
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

    /**
     * Note: This test is skipped because it requires database access.
     */
    #[Test]
    public function testVisualizeCitations_ValidRequest_SetsCORSHeaders(): void
    {
        $this->markTestSkipped('Database access issue - requires SQLite in-memory configuration');
    }

    /**
     * Note: This test is skipped because it requires database access.
     */
    #[Test]
    public function testVisualizeCitations_WithAllParameter_AcceptsParameter(): void
    {
        $this->markTestSkipped('Database access issue - requires SQLite in-memory configuration');
    }
}
