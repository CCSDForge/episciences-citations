<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OpenAccess;

use App\Services\OpenAccess\OpenAlexWorkParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit tests for OpenAlexWorkParser's payload-parsing algorithm.
 *
 * OpenAlexResolverTest already exercises this class indirectly through
 * OpenAlexResolver::resolve()/resolveMany(); these tests instead call
 * extractOaInfo()/normalizeDoi() directly to reach branches (resolveSourceTitle,
 * findJournalNameInLocations, findFirstAlternativeLocation, normalizeDoi edge
 * cases) that aren't all reachable through the resolver's own test fixtures.
 */
class OpenAlexWorkParserTest extends TestCase
{
    private OpenAlexWorkParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OpenAlexWorkParser();
    }

    #[Test]
    public function testExtractOaInfo_UsesBestOaLocationFirst(): void
    {
        // Arrange
        $work = [
            'best_oa_location' => ['source' => ['display_name' => 'Zenodo'], 'landing_page_url' => 'https://zenodo.org/x'],
            'primary_location' => ['is_oa' => true, 'source' => ['display_name' => 'Ignored'], 'landing_page_url' => 'https://ignored'],
            'locations' => [],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert - best_oa_location wins over primary_location even when both are usable
        $this->assertNotNull($result);
        $this->assertSame('https://zenodo.org/x', $result->url);
        $this->assertSame('Zenodo', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_BestOaLocationWithoutSourceArray_FallsThroughToPrimary(): void
    {
        // Arrange - best_oa_location present but malformed (no 'source' array)
        $work = [
            'best_oa_location' => ['landing_page_url' => 'https://malformed'],
            'primary_location' => ['is_oa' => true, 'source' => ['display_name' => 'HAL'], 'landing_page_url' => 'https://hal.science/x'],
            'locations' => [],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('https://hal.science/x', $result->url);
        $this->assertSame('HAL', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_PrimaryLocationIsOaButMissingSource_FallsThroughToLocations(): void
    {
        // Arrange - primary_location is_oa=true but has no usable 'source', must fall through
        // to scanning "locations".
        $work = [
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => true],
            'locations' => [
                ['is_oa' => true, 'source' => ['type' => 'journal', 'display_name' => 'Open Journal'], 'landing_page_url' => 'https://oj.example/x'],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('https://oj.example/x', $result->url);
        $this->assertSame('Open Journal', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_NonJournalOaLocation_ResolvesTitleFromJournalTypeElsewhere(): void
    {
        // Arrange - the matched OA location isn't itself a journal, so resolveSourceTitle()
        // must look elsewhere in "locations" for one whose source type is 'journal'. A malformed
        // entry (non-array source) in between exercises findJournalNameInLocations()'s "continue".
        $work = [
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false],
            'locations' => [
                ['is_oa' => true, 'source' => ['type' => 'repository', 'display_name' => 'Repo X'], 'landing_page_url' => 'https://repo.example/x'],
                ['source' => 'not-an-array'],
                ['source' => ['type' => 'journal', 'display_name' => 'The Real Journal']],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert - link comes from the repository location, but the title is the journal's
        $this->assertNotNull($result);
        $this->assertSame('https://repo.example/x', $result->url);
        $this->assertSame('The Real Journal', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_NonJournalOaLocation_ResolvesTitleFromAcceptedPublishedVersion(): void
    {
        // Arrange - no location has source type 'journal', but one is a published,
        // accepted "publishedVersion": findJournalNameInLocations()'s other match condition.
        $work = [
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false],
            'locations' => [
                ['is_oa' => true, 'source' => ['type' => 'repository', 'display_name' => 'Repo A'], 'landing_page_url' => 'https://repo-a.example/x'],
                [
                    'source' => ['type' => 'repository', 'display_name' => 'Repo B'],
                    'version' => 'publishedVersion',
                    'is_accepted' => true,
                    'is_published' => true,
                ],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('https://repo-a.example/x', $result->url);
        $this->assertSame('Repo B', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_NoJournalNameFound_FallsBackToOwnDisplayName(): void
    {
        // Arrange - resolveSourceTitle() must fall back to the OA location's own
        // display_name when findJournalNameInLocations() can't find anything.
        $work = [
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false],
            'locations' => [
                ['is_oa' => true, 'source' => ['type' => 'repository', 'display_name' => 'Repo Only'], 'landing_page_url' => 'https://repo-only.example/x'],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('Repo Only', $result->sourceTitle);
    }

    #[Test]
    public function testExtractOaInfo_NoOaLocationAnywhere_ReturnsNull(): void
    {
        // Arrange - nothing usable at all: findFirstAlternativeLocation() (dead-link fallback)
        // is exercised too (including its own "non-array source" skip), but since none of
        // those locations are OA, the final oa_link is always empty and extractOaInfo() must
        // return null.
        $work = [
            'best_oa_location' => null,
            'primary_location' => ['is_oa' => false, 'source' => ['display_name' => 'Publisher']],
            'locations' => [
                ['source' => 'not-an-array'],
                ['is_oa' => false, 'source' => ['display_name' => 'Closed repo']],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function testExtractOaInfo_EmptyWork_ReturnsNull(): void
    {
        // Arrange - a completely empty payload
        $result = $this->parser->extractOaInfo([]);

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function testExtractOaInfo_LocationsWithNonArraySource_AreSkipped(): void
    {
        // Arrange - malformed entries (source isn't an array) must be skipped both by
        // findLocationOaInfo() and findFirstAlternativeLocation()
        $work = [
            'best_oa_location' => null,
            'primary_location' => null,
            'locations' => [
                ['is_oa' => true, 'source' => 'not-an-array'],
                ['is_oa' => true, 'source' => ['type' => 'journal', 'display_name' => 'Valid Journal'], 'landing_page_url' => 'https://valid.example/x'],
            ],
        ];

        // Act
        $result = $this->parser->extractOaInfo($work);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('https://valid.example/x', $result->url);
        $this->assertSame('Valid Journal', $result->sourceTitle);
    }

    #[Test]
    public function testNormalizeDoi_StripsHttpsDoiOrgPrefixAndLowercases(): void
    {
        // Act
        $result = $this->parser->normalizeDoi('https://doi.org/10.1234/ABC');

        // Assert
        $this->assertSame('10.1234/abc', $result);
    }

    #[Test]
    public function testNormalizeDoi_StripsHttpDxDoiOrgPrefix(): void
    {
        // Act
        $result = $this->parser->normalizeDoi('http://dx.doi.org/10.1234/XYZ');

        // Assert
        $this->assertSame('10.1234/xyz', $result);
    }

    #[Test]
    public function testNormalizeDoi_UrlDecodesEncodedSlash(): void
    {
        // Act
        $result = $this->parser->normalizeDoi('10.1234%2FABC');

        // Assert
        $this->assertSame('10.1234/abc', $result);
    }

    #[Test]
    public function testNormalizeDoi_TrimsWhitespace(): void
    {
        // Act
        $result = $this->parser->normalizeDoi('  10.1234/ABC  ');

        // Assert
        $this->assertSame('10.1234/abc', $result);
    }

    #[Test]
    public function testNormalizeDoi_NonStringInput_ReturnsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->parser->normalizeDoi(1234));
        $this->assertNull($this->parser->normalizeDoi(null));
        $this->assertNull($this->parser->normalizeDoi(['10.1234/abc']));
    }

    #[Test]
    public function testNormalizeDoi_EmptyOrWhitespaceOnlyString_ReturnsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->parser->normalizeDoi(''));
        $this->assertNull($this->parser->normalizeDoi('   '));
    }
}
