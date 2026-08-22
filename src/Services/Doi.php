<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Doi
{
    public const DOI_URL = 'https://doi.org/';
    public function getCsl(string $doi): string
    {
        $client = new Client();
        try {
            $response = $client->get(self::DOI_URL.$doi, [
                'headers' => [
                    'Accept' => 'application/vnd.citationstyles.csl+json',
                    'Content-type' => "application/json"
                ]
            ]);
            return $response->getBody()->getContents();
        } catch (GuzzleException) {
            return "";
        }
    }
    public function getBibtex(string $doi): string
    {
        $client = new Client();
        try {
            $response = $client->get(self::DOI_URL.$doi, [
                'headers' => [
                    'Accept' => 'application/x-bibtex',
                ]
            ]);
            return $response->getBody()->getContents();
        } catch (GuzzleException) {
            return "";
        }
    }

    public function getFormattedCitation(string $doi, string $style = 'apa', string $lang = 'en-GB'): string
    {
        $client = new Client();
        try {
            $response = $client->get('https://citation.doi.org/format', [
                'query' => [
                    'doi' => $doi,
                    'style' => $style,
                    'lang' => $lang
                ]
            ]);
            $citation = trim($response->getBody()->getContents());
            return self::stripTrailingDoi($citation, $doi);
        } catch (GuzzleException) {
            return "";
        }
    }

    public static function stripTrailingDoi(string $text, ?string $doi = null): string
    {
        if ($doi !== null && $doi !== '') {
            $pattern = '#\s*(?:https?://(?:dx\.)?doi\.org/|doi:\s*)' . preg_quote($doi, '#') . '\.?$#i';
            return preg_replace($pattern, '', $text) ?? $text;
        }
        $generalPattern = '#\s*(?:https?://(?:dx\.)?doi\.org/|doi:\s*)10\.\d{4,}(?:\.\d+)*\/(?:(?!["&\'\s])\S)+?\.?$#i';
        return preg_replace($generalPattern, '', $text) ?? $text;
    }



    /**
     * @param array<string, mixed> $csl
     * @return array<int, array<string, mixed>>
     */
    public function retrieveReferencesFromCsl(array $csl): array
    {
        $refs = [];
        $i = 0;
        foreach ($csl['reference'] as $refInfo){
            $refs[$i]['raw_reference'] = $refInfo['unstructured'];
            if (isset($refInfo['DOI'])){
                $refs[$i]['doi'] = $refInfo['DOI'];
            }
            $i++;
        }
        return $refs;
    }
}
