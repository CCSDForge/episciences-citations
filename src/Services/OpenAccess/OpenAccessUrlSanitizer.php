<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

/**
 * Restricts open-access links to absolute http(s) URLs before they are persisted or rendered.
 *
 * Browsers strip ASCII tab/CR/LF from a URL wherever they occur before parsing its scheme, so a
 * blocklist like "does not start with javascript:" can be bypassed by splitting the scheme with
 * one of those characters (e.g. "java\tscript:alert(1)"). Stripping them first, then requiring
 * the result to start with http:// or https://, closes that bypass instead of trying to enumerate
 * every dangerous scheme.
 */
final class OpenAccessUrlSanitizer
{
    public static function sanitize(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $cleaned = preg_replace('/[\t\r\n]/', '', trim($url)) ?? '';

        return preg_match('#^https?://\S+#i', $cleaned) === 1 ? $cleaned : null;
    }
}
