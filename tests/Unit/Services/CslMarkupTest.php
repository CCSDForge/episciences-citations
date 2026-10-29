<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\CslMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CslMarkupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markupProvider(): iterable
    {
        yield 'plain text is untouched' => ['A plain title', 'A plain title'];
        yield 'crossref superscript with line breaks' => [
            "An objective and accurate G\n                    <sup>1</sup>\n                    -conforming mixed Bézier FE-formulation",
            'An objective and accurate G¹-conforming mixed Bézier FE-formulation',
        ];
        yield 'inline superscript' => ['G<sup>1</sup>-conforming', 'G¹-conforming'];
        yield 'subscript' => ['H<sub>2</sub>O', 'H₂O'];
        yield 'superscript with sign' => ['Ca<sup>2+</sup> ions', 'Ca²⁺ ions'];
        yield 'unmappable superscript keeps its text' => ['Space W<sup>Q</sup>', 'Space WQ'];
        yield 'italic is dropped, spacing kept' => ['The <i>in vivo</i> study', 'The in vivo study'];
        yield 'nocase span is dropped' => ['<span class="nocase">iPhone</span> apps', 'iPhone apps'];
        yield 'mathml keeps its text' => [
            'A <mml:math><mml:msup><mml:mi>C</mml:mi><mml:mn>1</mml:mn></mml:msup></mml:math> scheme',
            'A C1 scheme',
        ];
        yield 'entities are decoded' => ['Mechanics &amp; Solids', 'Mechanics & Solids'];
        yield 'escaped tags are decoded, not stripped' => ['x &lt;sup&gt; y', 'x <sup> y'];
        yield 'lone comparison sign is kept' => ['Effect for p < 0.05 and q > 1', 'Effect for p < 0.05 and q > 1'];
        yield 'glued comparison signs are kept' => ['Effect p<0.05 when x<y and y>z', 'Effect p<0.05 when x<y and y>z'];
        yield 'crossref face markup is dropped' => ['<scp>Rna</scp> and <ovl>x</ovl>', 'Rna and x'];
        yield 'jats markup is dropped' => ['<jats:italic>In vivo</jats:italic> data', 'In vivo data'];
    }

    #[Test]
    #[DataProvider('markupProvider')]
    public function testToPlainText(string $input, string $expected): void
    {
        $this->assertSame($expected, CslMarkup::toPlainText($input));
    }

    #[Test]
    public function testToPlainTextRecursive_CleansNestedStringsAndKeepsOtherTypes(): void
    {
        $csl = [
            'title' => 'G<sup>1</sup>-conforming',
            'author' => [['family' => '<i>Greco</i>', 'given' => 'L.']],
            'issued' => ['date-parts' => [[2023]]],
            'volume' => 29,
        ];

        $result = CslMarkup::toPlainTextRecursive($csl);

        $this->assertSame('G¹-conforming', $result['title']);
        $this->assertSame('Greco', $result['author'][0]['family']);
        $this->assertSame([[2023]], $result['issued']['date-parts']);
        $this->assertSame(29, $result['volume']);
    }
}
