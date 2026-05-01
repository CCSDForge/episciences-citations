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

    /**
     * @param string $doi
     * @param string $style
     * @param string $lang
     * @return string
     */
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
            return trim($response->getBody()->getContents());
        } catch (GuzzleException) {
            return "";
        }
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
