<?php

namespace App\Tests\Unit\Services;

use App\Services\Semanticsscholar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour le service Semanticsscholar
 *
 * Note: La méthode getRef() utilise GuzzleHttp\Client directement
 * (pas d'injection de dépendance) et fait un sleep(1), donc elle n'est pas
 * facilement testable unitairement.
 *
 * Ces tests vérifient uniquement le comportement de base.
 * Des tests d'intégration complets nécessiteraient l'API Semantic Scholar réelle.
 */
class SemanticsscholarTest extends TestCase
{
    private Semanticsscholar $service;
    private string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        $this->service = new Semanticsscholar($this->apiKey);
    }

    #[Test]
    public function testGetRef_ReturnsString(): void
    {
        // Arrange
        $doi = '10.1234/test';

        // Act
        $result = $this->service->getRef($doi);

        // Assert - vérifie juste que la méthode retourne une string
        // (tests d'intégration complets nécessiteraient l'API réelle)
        $this->assertIsString($result);
    }

    #[Test]
    public function testConstant_S2_URL_IsCorrect(): void
    {
        // Assert - vérifier les constantes de l'API
        $this->assertEquals(
            'https://api.semanticscholar.org/graph/v1/paper/DOI:',
            Semanticsscholar::S2_URL
        );
    }

    #[Test]
    public function testConstant_S2_ARG_IsCorrect(): void
    {
        // Assert - vérifier les arguments de l'API
        $this->assertEquals(
            '?fields=title,authors,externalIds,citationStyles',
            Semanticsscholar::S2_ARG
        );
    }

    #[Test]
    public function testConstructor_AcceptsApiKey(): void
    {
        // Arrange & Act
        $customApiKey = 'custom-test-key-12345';
        $service = new Semanticsscholar($customApiKey);

        // Assert - vérifier que le service est créé correctement
        $this->assertInstanceOf(Semanticsscholar::class, $service);
    }
}
