<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Semanticsscholar
{
    public const S2_URL = 'https://api.semanticscholar.org/graph/v1/paper/DOI:';
    public const S2_ARG = '?fields=title,authors,externalIds,citationStyles';

    public function __construct(private string $apiKeyS2) {

    }
    public function getRef(string $doi)
    {
        sleep(1);
        $client = new Client();
        try {
            $response = $client->get(self::S2_URL . $doi.'/references'.self::S2_ARG, [
                'headers' => [
                    'User-Agent' => 'CCSD Episciences support@episciences.org',
                    'Accept' => 'application/json',
                    'Content-type' => "application/json",
                    'apiKey'=> $this->apiKeyS2,
                ]
            ]);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return "";
        }

    }
}
