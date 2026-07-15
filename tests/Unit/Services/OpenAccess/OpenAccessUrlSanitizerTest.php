<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OpenAccess;

use App\Services\OpenAccess\OpenAccessUrlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenAccessUrlSanitizerTest extends TestCase
{
    #[Test]
    public function testAcceptsHttpsUrl(): void
    {
        $this->assertSame('https://oa.example.org/paper', OpenAccessUrlSanitizer::sanitize('https://oa.example.org/paper'));
    }

    #[Test]
    public function testAcceptsHttpUrl(): void
    {
        $this->assertSame('http://oa.example.org/paper', OpenAccessUrlSanitizer::sanitize('http://oa.example.org/paper'));
    }

    #[Test]
    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('https://oa.example.org/paper', OpenAccessUrlSanitizer::sanitize("  https://oa.example.org/paper  \n"));
    }

    #[Test]
    public function testRejectsNull(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize(null));
    }

    #[Test]
    public function testRejectsEmptyString(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize(''));
    }

    #[Test]
    public function testRejectsNonStringInput(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize(['url' => 'https://oa.example.org']));
    }

    #[Test]
    public function testRejectsJavascriptScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize('javascript:alert(1)'));
    }

    #[Test]
    public function testRejectsDataScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize('data:text/html,<script>alert(1)</script>'));
    }

    #[Test]
    public function testRejectsVbscriptScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize('vbscript:msgbox(1)'));
    }

    #[Test]
    public function testRejectsProtocolRelativeUrl(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize('//evil.example.org/x'));
    }

    /**
     * Browsers strip ASCII tab/CR/LF from a URL wherever they occur before parsing its scheme,
     * so a payload that splits "javascript:" with one of those characters still executes when
     * clicked even though it doesn't literally start with "javascript:". A blocklist regex like
     * /^(javascript:|data:|vbscript:)/i misses this; the sanitizer must normalize first.
     */
    #[Test]
    public function testRejectsTabSplitJavascriptScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize("java\tscript:alert(1)"));
    }

    #[Test]
    public function testRejectsNewlineSplitJavascriptScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize("java\nscript:alert(1)"));
    }

    #[Test]
    public function testRejectsCarriageReturnSplitJavascriptScheme(): void
    {
        $this->assertNull(OpenAccessUrlSanitizer::sanitize("java\rscript:alert(1)"));
    }
}
