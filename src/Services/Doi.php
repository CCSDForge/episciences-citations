<?php

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
        } catch (GuzzleException $e) {
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
        } catch (GuzzleException $e) {
            return "";
        }
    }



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