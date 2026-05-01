<?php

namespace App\Tests\Unit\Twig;

use App\Twig\JsonGrobidExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JsonGrobidExtensionTest extends TestCase
{
    private JsonGrobidExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new JsonGrobidExtension();
    }

    #[Test]
    public function testPrettyReference_FlatReferenceWithoutCsl_ReturnsDecodedArray(): void
    {
        // Arrange — JSON string of a flat array (output of JsonTransformer::transform)
        $refData = ['raw_reference' => 'Author et al. Title. Journal, 2024.', 'doi' => '10.1/x'];
        $jsonInput = json_encode($refData);

        // Act
        $result = $this->extension->prettyReference($jsonInput);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertEquals('Author et al. Title. Journal, 2024.', $result['raw_reference']);
        $this->assertArrayHasKey('doi', $result);
        $this->assertArrayNotHasKey('csl', $result);
        $this->assertArrayNotHasKey('forbiddenModify', $result);
    }

    #[Test]
    public function testPrettyReference_ReferenceWithCsl_RendersAndSetsForbiddenModify(): void
    {
        // Arrange — reference with CSL data
        $refData = [
            'raw_reference' => 'Original text',
            'csl' => [
                'type' => 'article-journal',
                'title' => 'Test Article',
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
                'container-title' => 'Test Journal',
            ],
        ];
        $jsonInput = json_encode($refData);

        // Act
        $result = $this->extension->prettyReference($jsonInput);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('csl', $result, 'CSL key should be removed after rendering');
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertStringContainsString('Doe', $result['raw_reference']);
        $this->assertEquals(1, $result['forbiddenModify']);
    }

    #[Test]
    public function testPrettyReference_EmptyString_ReturnsEmptyArray(): void
    {
        $result = $this->extension->prettyReference('');
        $this->assertEquals([], $result);
    }

    #[Test]
    public function testPrettyReference_InvalidJson_ReturnsEmptyArray(): void
    {
        $result = $this->extension->prettyReference('not valid json {{{');
        $this->assertEquals([], $result);
    }

    #[Test]
    public function testGetFunctions_ContainsExpectedFunctions(): void
    {
        $registeredNames = [];
        $reflection = new \ReflectionClass(JsonGrobidExtension::class);
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(\Twig\Attribute\AsTwigFunction::class) as $attribute) {
                $registeredNames[] = $attribute->newInstance()->name;
            }
        }

        $this->assertContains('getAuthors', $registeredNames);
        $this->assertContains('getDateInJson', $registeredNames);
        $this->assertContains('getJournalIdentifier', $registeredNames);
        $this->assertContains('prettyReference', $registeredNames);
    }
}
