<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Doi
{
    public const DOI_URL = 'https://doi.org/';
    public function getCsl(string $doi)
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
}