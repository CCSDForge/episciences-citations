<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Flattens the rich-text markup found in Crossref metadata (CSL-JSON fields such as titles,
 * e.g. "G <sup>1</sup>-conforming", and "unstructured" reference strings) into plain text,
 * since citeproc-php escapes it instead of interpreting it and the tags would end up
 * displayed in the formatted reference.
 */
final class CslMarkup
{
    /**
     * Known inline markup only, so that a bare "<" (e.g. "p<0.05") is kept, unlike with strip_tags():
     * - CSL rich text: https://citeproc-js.readthedocs.io/en/latest/csl-json/markup.html
     * - Crossref face markup: https://www.crossref.org/documentation/schema-library/markup-guide-metadata-segments/face-markup/
     * - namespaced MathML/JATS (mml:math, jats:italic...), allowed by Crossref in titles
     * - em, strong and sc (Zotero small caps), found in publisher and repository metadata
     */
    private const string TAG_PATTERN = '#</?(?:i|b|u|em|strong|sup|sub|sc|scp|tt|ovl|span|[a-z]+:[\w.-]+)(?:\s[^<>]*)?/?>#i';

    private const array SUPERSCRIPTS = [
        '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴',
        '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹',
        '+' => '⁺', '-' => '⁻', '−' => '⁻', '=' => '⁼', '(' => '⁽', ')' => '⁾',
        'a' => 'ᵃ', 'b' => 'ᵇ', 'c' => 'ᶜ', 'd' => 'ᵈ', 'e' => 'ᵉ', 'f' => 'ᶠ',
        'g' => 'ᵍ', 'h' => 'ʰ', 'i' => 'ⁱ', 'j' => 'ʲ', 'k' => 'ᵏ', 'l' => 'ˡ',
        'm' => 'ᵐ', 'n' => 'ⁿ', 'o' => 'ᵒ', 'p' => 'ᵖ', 'r' => 'ʳ', 's' => 'ˢ',
        't' => 'ᵗ', 'u' => 'ᵘ', 'v' => 'ᵛ', 'w' => 'ʷ', 'x' => 'ˣ', 'y' => 'ʸ', 'z' => 'ᶻ',
        'A' => 'ᴬ', 'B' => 'ᴮ', 'D' => 'ᴰ', 'E' => 'ᴱ', 'G' => 'ᴳ', 'H' => 'ᴴ', 'I' => 'ᴵ',
        'J' => 'ᴶ', 'K' => 'ᴷ', 'L' => 'ᴸ', 'M' => 'ᴹ', 'N' => 'ᴺ', 'O' => 'ᴼ', 'P' => 'ᴾ',
        'R' => 'ᴿ', 'T' => 'ᵀ', 'U' => 'ᵁ', 'V' => 'ⱽ', 'W' => 'ᵂ',
    ];

    private const array SUBSCRIPTS = [
        '0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄',
        '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉',
        '+' => '₊', '-' => '₋', '−' => '₋', '=' => '₌', '(' => '₍', ')' => '₎',
        'a' => 'ₐ', 'e' => 'ₑ', 'h' => 'ₕ', 'i' => 'ᵢ', 'j' => 'ⱼ', 'k' => 'ₖ',
        'l' => 'ₗ', 'm' => 'ₘ', 'n' => 'ₙ', 'o' => 'ₒ', 'p' => 'ₚ', 'r' => 'ᵣ',
        's' => 'ₛ', 't' => 'ₜ', 'u' => 'ᵤ', 'v' => 'ᵥ', 'x' => 'ₓ',
    ];

    /**
     * @param array<array-key, mixed> $csl
     * @return array<array-key, mixed>
     */
    public static function toPlainTextRecursive(array $csl): array
    {
        array_walk_recursive($csl, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::toPlainText($value);
            }
        });

        return $csl;
    }

    public static function toPlainText(string $text): string
    {
        if (!str_contains($text, '<') && !str_contains($text, '&')) {
            return $text;
        }

        // Decode first: some sources escape their markup ("&lt;i&gt;"), which must be stripped too.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Crossref wraps inline tags in line breaks ("G\n    <sup>1</sup>\n    -conforming"):
        // that whitespace is layout, not content, so glue super/subscripts to the preceding word
        // and to following punctuation; other line breaks become a single space below.
        $text = preg_replace('#\s*\n\s*(<(su[bp])\b[^>]*>)#i', '$1', $text) ?? $text;
        $text = preg_replace('#(</su[bp]\s*>)\s*\n\s*(?=[\p{Pd}\p{Pe},.;:!?%])#iu', '$1', $text) ?? $text;

        $text = preg_replace_callback(
            '#<(su[bp])\b[^>]*>(.*?)</\1\s*>#is',
            static fn(array $m): string => self::toScript(
                self::stripTags($m[2]),
                strtolower($m[1]) === 'sup' ? self::SUPERSCRIPTS : self::SUBSCRIPTS
            ),
            $text
        ) ?? $text;

        $text = self::stripTags($text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function stripTags(string $text): string
    {
        return preg_replace(self::TAG_PATTERN, '', $text) ?? $text;
    }

    /**
     * Returns the Unicode super/subscript form when every character has one, the text unchanged otherwise.
     *
     * @param array<int|string, string> $map digit keys are cast to int by PHP
     */
    private static function toScript(string $text, array $map): string
    {
        $chars = mb_str_split(trim($text));
        foreach ($chars as $char) {
            if (!isset($map[$char])) {
                return $text;
            }
        }

        return implode('', array_map(static fn(string $char): string => $map[$char], $chars));
    }
}
