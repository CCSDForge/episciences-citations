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
        yield 'word after superscript line break keeps its space' => [
            "Study of virus<sup>1</sup>\n    and its spread",
            'Study of virus¹ and its spread',
        ];
        yield 'entity inside superscript' => ['m s<sup>&minus;1</sup>', 'm s⁻¹'];
        yield 'space in closing tag' => ['G<sup>1</sup >-conforming', 'G¹-conforming'];
        yield 'transpose superscript' => ['A<sup>T</sup>x', 'Aᵀx'];
        yield 'superscript with sign' => ['Ca<sup>2+</sup> ions', 'Ca²⁺ ions'];
        yield 'unmappable superscript keeps its text' => ['Space W<sup>Q</sup>', 'Space WQ'];
        yield 'tag-like word is not a tag' => ['An <input> and <bold> element', 'An <input> and <bold> element'];
        yield 'italic is dropped, spacing kept' => ['The <i>in vivo</i> study', 'The in vivo study'];
        yield 'nocase span is dropped' => ['<span class="nocase">iPhone</span> apps', 'iPhone apps'];
        yield 'mathml keeps its text' => [
            'A <mml:math><mml:msup><mml:mi>C</mml:mi><mml:mn>1</mml:mn></mml:msup></mml:math> scheme',
            'A C1 scheme',
        ];
        yield 'entities are decoded' => ['Mechanics &amp; Solids', 'Mechanics & Solids'];
        yield 'escaped markup is decoded then flattened' => [
            'Title with &lt;i&gt;italic&lt;/i&gt; and x&lt;sup&gt;2&lt;/sup&gt;',
            'Title with italic and x²',
        ];
        yield 'escaped comparison sign is decoded' => ['Effect for p &lt; 0.05', 'Effect for p < 0.05'];
        yield 'small caps, em and strong are dropped' => [
            'The <sc>Unesco</sc> Courier and <em>science</em> <strong>now</strong>',
            'The Unesco Courier and science now',
        ];
        yield 'bracket after superscript line break keeps its space' => [
            "Study of virus<sup>1</sup>\n    (note)",
            'Study of virus¹ (note)',
        ];
        yield 'punctuation after superscript line break is glued' => ["x<sup>2</sup>\n    , y", 'x², y'];
        yield 'uppercase superscript' => ['10<sup>TM</sup> kit', '10ᵀᴹ kit'];
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
