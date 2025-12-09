<?php

namespace App\Tests\Unit\Services;

use App\Services\Doi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour le service Doi
 *
 * Note: Les méthodes getCsl() et getBibtex() utilisent GuzzleHttp\Client directement
 * (pas d'injection de dépendance), donc elles ne sont pas facilement testables unitairement.
 * Ces méthodes nécessiteraient des tests d'intégration avec l'API doi.org réelle.
 *
 * Nous testons ici la logique pure : retrieveReferencesFromCsl()
 */
class DoiTest extends TestCase
{
    private Doi $service;

    protected function setUp(): void
    {
        $this->service = new Doi();
    }

    #[Test]
    public function testRetrieveReferencesFromCsl_ValidCslWithDoi_ExtractsReferences(): void
    {
        // Arrange - CSL avec 2 références incluant DOI
        $csl = [
            'reference' => [
                [
                    'unstructured' => 'Smith J., Johnson A. (2020). Test Article 1. Journal of Testing, 10(2), 123-145.',
                    'DOI' => '10.1234/test1'
                ],
                [
                    'unstructured' => 'Brown K., Davis L. (2021). Test Article 2. Conference Proceedings, 5, 67-89.',
                    'DOI' => '10.5678/test2'
                ]
            ]
        ];

        // Act
        $result = $this->service->retrieveReferencesFromCsl($csl);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // Première référence
        $this->assertArrayHasKey('raw_reference', $result[0]);
        $this->assertArrayHasKey('doi', $result[0]);
        $this->assertEquals('Smith J., Johnson A. (2020). Test Article 1. Journal of Testing, 10(2), 123-145.', $result[0]['raw_reference']);
        $this->assertEquals('10.1234/test1', $result[0]['doi']);

        // Deuxième référence
        $this->assertArrayHasKey('raw_reference', $result[1]);
        $this->assertArrayHasKey('doi', $result[1]);
        $this->assertEquals('Brown K., Davis L. (2021). Test Article 2. Conference Proceedings, 5, 67-89.', $result[1]['raw_reference']);
        $this->assertEquals('10.5678/test2', $result[1]['doi']);
    }

    #[Test]
    public function testRetrieveReferencesFromCsl_ValidCslWithoutDoi_ExtractsReferencesWithoutDoi(): void
    {
        // Arrange - CSL avec références SANS DOI
        $csl = [
            'reference' => [
                [
                    'unstructured' => 'Anonymous Author (2019). Unpublished Work. Internal Report.'
                ],
                [
                    'unstructured' => 'Historic Document (1850). Ancient Text. Archive Collection.'
                ]
            ]
        ];

        // Act
        $result = $this->service->retrieveReferencesFromCsl($csl);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // Première référence - pas de DOI
        $this->assertArrayHasKey('raw_reference', $result[0]);
        $this->assertArrayNotHasKey('doi', $result[0]);
        $this->assertEquals('Anonymous Author (2019). Unpublished Work. Internal Report.', $result[0]['raw_reference']);

        // Deuxième référence - pas de DOI
        $this->assertArrayHasKey('raw_reference', $result[1]);
        $this->assertArrayNotHasKey('doi', $result[1]);
        $this->assertEquals('Historic Document (1850). Ancient Text. Archive Collection.', $result[1]['raw_reference']);
    }

    #[Test]
    public function testRetrieveReferencesFromCsl_MixedDoi_ExtractsCorrectly(): void
    {
        // Arrange - CSL mixte (certaines avec DOI, d'autres sans)
        $csl = [
            'reference' => [
                [
                    'unstructured' => 'First Reference with DOI.',
                    'DOI' => '10.1111/example1'
                ],
                [
                    'unstructured' => 'Second Reference without DOI.'
                ],
                [
                    'unstructured' => 'Third Reference with DOI.',
                    'DOI' => '10.2222/example2'
                ]
            ]
        ];

        // Act
        $result = $this->service->retrieveReferencesFromCsl($csl);

        // Assert
        $this->assertCount(3, $result);

        // Vérifier présence/absence de DOI
        $this->assertArrayHasKey('doi', $result[0]);
        $this->assertEquals('10.1111/example1', $result[0]['doi']);

        $this->assertArrayNotHasKey('doi', $result[1]);

        $this->assertArrayHasKey('doi', $result[2]);
        $this->assertEquals('10.2222/example2', $result[2]['doi']);
    }

    #[Test]
    public function testRetrieveReferencesFromCsl_EmptyReferences_ReturnsEmptyArray(): void
    {
        // Arrange - CSL avec tableau de références vide
        $csl = [
            'reference' => []
        ];

        // Act
        $result = $this->service->retrieveReferencesFromCsl($csl);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function testRetrieveReferencesFromCsl_SingleReference_ReturnsArrayWithOneElement(): void
    {
        // Arrange - CSL avec une seule référence
        $csl = [
            'reference' => [
                [
                    'unstructured' => 'Single Reference Test.',
                    'DOI' => '10.9999/single'
                ]
            ]
        ];

        // Act
        $result = $this->service->retrieveReferencesFromCsl($csl);

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals('Single Reference Test.', $result[0]['raw_reference']);
        $this->assertEquals('10.9999/single', $result[0]['doi']);
    }

    #[Test]
    public function testGetCsl_ReturnsString(): void
    {
        // Arrange
        $doi = '10.1234/test';

        // Act
        $result = $this->service->getCsl($doi);

        // Assert - vérifie juste que la méthode retourne une string
        // (tests d'intégration complets nécessiteraient l'API réelle)
        $this->assertIsString($result);
    }

    #[Test]
    public function testGetBibtex_ReturnsString(): void
    {
        // Arrange
        $doi = '10.1234/test';

        // Act
        $result = $this->service->getBibtex($doi);

        // Assert - vérifie juste que la méthode retourne une string
        // (tests d'intégration complets nécessiteraient l'API réelle)
        $this->assertIsString($result);
    }
}
