<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use Twig\Attribute\AsTwigFunction;
use App\Services\Bibtex;
use App\Twig\JsonGrobidExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class JsonGrobidExtensionTest extends TestCase
{
    private JsonGrobidExtension $extension;
    private Bibtex&Stub $bibtex;

    protected function setUp(): void
    {
        // A stub is enough for every test in this file except the two that
        // assert Bibtex::getCslRefText() is actually called (those build
        // their own createMock() with expects() locally, see below), so the
        // shared fixture doesn't trigger "mock created but no expectations
        // configured" notices on the majority of tests that never touch it.
        $this->bibtex = $this->createStub(Bibtex::class);
        $this->extension = new JsonGrobidExtension($this->bibtex);
    }

    #[Test]
    public function testPrettyReference_FlatReferenceWithoutCsl_ReturnsDecodedArray(): void
    {
        // Arrange — JSON string of a flat array (output of JsonTransformer::transform)
        $refData = ['raw_reference' => 'Author et al. Title. Journal, 2024.', 'doi' => '10.1/x'];
        $this->bibtex->method('getCslRefText')->willReturnArgument(0);
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
    public function testPrettyReference_FlatReferenceWithTrailingDoi_StripsTrailingDoi(): void
    {
        $refData = [
            'raw_reference' => 'Laiarinandrasana, L. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            'doi' => '10.46298/jtcam.11335'
        ];
        $this->bibtex->method('getCslRefText')->willReturnArgument(0);
        $jsonInput = json_encode($refData);

        $result = $this->extension->prettyReference($jsonInput);

        $this->assertIsArray($result);
        $this->assertSame('Laiarinandrasana, L. (2024). Title. Journal.', $result['raw_reference']);
        $this->assertSame('10.46298/jtcam.11335', $result['doi']);
    }

    #[Test]
    public function testPrettyReference_ReferenceWithCsl_RendersReference(): void
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
        $rendered = ['raw_reference' => 'Doe, J. (2024). Test Article. Test Journal.'];
        $bibtex = $this->createMock(Bibtex::class);
        $bibtex->expects($this->once())
            ->method('getCslRefText')
            ->with($refData)
            ->willReturn($rendered);
        $extension = new JsonGrobidExtension($bibtex);
        $jsonInput = json_encode($refData);

        // Act
        $result = $extension->prettyReference($jsonInput);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('csl', $result, 'CSL key should be removed after rendering');
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertStringContainsString('Doe', $result['raw_reference']);
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
    public function testGetAuthors_SimpleForenameAndSurname_ReturnsAuthorInfo(): void
    {
        $authors = [
            [
                'persName' => [
                    'forename' => 'John',
                    'surname' => 'Doe',
                ],
            ],
        ];

        $result = $this->extension->getAuthors($authors);

        $this->assertCount(1, $result);
        $this->assertSame('John', $result[0]['forename']);
        $this->assertSame('Doe', $result[0]['surname']);
        $this->assertNull($result[0]['orcid']);
    }

    #[Test]
    public function testGetAuthors_WithOrcid_ReturnsOrcidValue(): void
    {
        $authors = [
            [
                'persName' => [
                    'forename' => 'John',
                    'surname' => 'Doe',
                ],
                'idno' => '0000-0001-2345-6789',
            ],
        ];

        $result = $this->extension->getAuthors($authors);

        $this->assertSame('0000-0001-2345-6789', $result[0]['orcid']);
    }

    #[Test]
    public function testGetAuthors_ArrayForename_ComposesNames(): void
    {
        $authors = [
            [
                'persName' => [
                    'forename' => ['John', 'Michael'],
                    'surname' => 'Doe',
                ],
            ],
        ];

        $result = $this->extension->getAuthors($authors);

        $this->assertSame('John Michael', $result[0]['forename']);
        $this->assertSame('Doe', $result[0]['surname']);
    }

    #[Test]
    public function testGetAuthors_ArraySurname_ComposesNames(): void
    {
        $authors = [
            [
                'persName' => [
                    'forename' => 'Jean',
                    'surname' => ['Van', 'Damme'],
                ],
            ],
        ];

        $result = $this->extension->getAuthors($authors);

        $this->assertSame('Jean', $result[0]['forename']);
        $this->assertSame('Van Damme', $result[0]['surname']);
    }

    #[Test]
    public function testGetAuthors_MissingPersName_IsSkipped(): void
    {
        $authors = [
            ['persName' => ['forename' => 'John']], // missing surname
            [], // missing persName entirely
        ];

        $result = $this->extension->getAuthors($authors);

        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetOrcid_IdnoPresent_ReturnsIdno(): void
    {
        $result = $this->extension->getOrcid(['idno' => '0000-0001-2345-6789']);

        $this->assertSame('0000-0001-2345-6789', $result);
    }

    #[Test]
    public function testGetOrcid_IdnoMissing_ReturnsNull(): void
    {
        $result = $this->extension->getOrcid([]);

        $this->assertNull($result);
    }

    #[Test]
    public function testComposeNames_ListOfNames_JoinsWithSpace(): void
    {
        $result = $this->extension->composeNames(['Jean', 'Paul', 'Gaultier']);

        $this->assertSame('Jean Paul Gaultier', $result);
    }

    #[Test]
    public function testGetDateInJson_StringDate_ReturnsAsIs(): void
    {
        $result = $this->extension->getDateInJson('2024-01-01');

        $this->assertSame('2024-01-01', $result);
    }

    #[Test]
    public function testGetDateInJson_ArrayWithWhenAttribute_ReturnsWhenValue(): void
    {
        $result = $this->extension->getDateInJson([['when' => '2024-05-01']]);

        $this->assertSame('2024-05-01', $result);
    }

    #[Test]
    public function testGetDateInJson_ArrayWithoutWhenAttribute_ReturnsOriginalArray(): void
    {
        $date = [['other' => 'value']];

        $result = $this->extension->getDateInJson($date);

        $this->assertSame($date, $result);
    }

    #[Test]
    public function testGetJournalIdentifier_StringIdentifier_ReturnsAsIs(): void
    {
        $result = $this->extension->getJournalIdentifier('1234-5678');

        $this->assertSame('1234-5678', $result);
    }

    #[Test]
    public function testGetJournalIdentifier_ArrayIdentifier_JoinsWithSemicolon(): void
    {
        $result = $this->extension->getJournalIdentifier(['1234-5678', '8765-4321']);

        $this->assertSame('1234-5678; 8765-4321', $result);
    }

    #[Test]
    public function testPrettyReference_LegacyArrayWrappedObject_UnwrapsAndRenders(): void
    {
        // Legacy double-encoding: JSON array containing one object
        $inner = ['raw_reference' => 'Legacy wrapped reference'];
        $this->bibtex->method('getCslRefText')->willReturnArgument(0);
        $jsonInput = json_encode([$inner]);

        $result = $this->extension->prettyReference($jsonInput);

        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertSame('Legacy wrapped reference', $result['raw_reference']);
    }

    #[Test]
    public function testPrettyReference_LegacyArrayWrappedJsonString_UnwrapsAndRenders(): void
    {
        // Legacy double-encoding: JSON array containing a JSON-encoded string
        $inner = ['raw_reference' => 'Legacy double-encoded reference'];
        $this->bibtex->method('getCslRefText')->willReturnArgument(0);
        $jsonInput = json_encode([json_encode($inner)]);

        $result = $this->extension->prettyReference($jsonInput);

        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertSame('Legacy double-encoded reference', $result['raw_reference']);
    }

    #[Test]
    public function testPrettyReference_ArrayWrappedNonJsonString_ReturnsOriginalList(): void
    {
        // A single-element list wrapping a plain (non-JSON) string cannot be
        // unwrapped, so unwrapLegacyEncoding leaves it untouched and it is
        // passed through to Bibtex::getCslRefText as-is.
        $bibtex = $this->createMock(Bibtex::class);
        $bibtex->expects($this->once())
            ->method('getCslRefText')
            ->with([0 => 'not json'])
            ->willReturn(['raw_reference' => 'not json']);
        $extension = new JsonGrobidExtension($bibtex);
        $jsonInput = json_encode(['not json']);

        $result = $extension->prettyReference($jsonInput);

        $this->assertSame('not json', $result['raw_reference']);
    }

    #[Test]
    public function testPrettyReference_NonArrayJson_ReturnsEmptyArray(): void
    {
        // A JSON scalar (e.g. a bare string) decodes successfully but is not an array
        $result = $this->extension->prettyReference('"just a string"');

        $this->assertEquals([], $result);
    }

    #[Test]
    public function testGetFunctions_ContainsExpectedFunctions(): void
    {
        $registeredNames = [];
        $reflection = new \ReflectionClass(JsonGrobidExtension::class);
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(AsTwigFunction::class) as $attribute) {
                $registeredNames[] = $attribute->newInstance()->name;
            }
        }

        $this->assertContains('getAuthors', $registeredNames);
        $this->assertContains('getDateInJson', $registeredNames);
        $this->assertContains('getJournalIdentifier', $registeredNames);
        $this->assertContains('prettyReference', $registeredNames);
    }
}
