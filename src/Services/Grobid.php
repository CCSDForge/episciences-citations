<?php

namespace App\Services;

use App\Entity\PaperReferences;
use Doctrine\ORM\EntityManagerInterface;
use http\Url;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use App\Services\Tei;

class Grobid {

    public function __construct(
        private HttpClientInterface $client,
        private Tei $tei,
        private EntityManagerInterface $entityManager,
        private string $cacheFolder,
        private string $grobidUrl
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws \JsonException
     */
    public function insertReferences($pdf): void
    {
        $referencesExist = $this->getGrobidReferencesInCache("6816.pdf");
        if (!$referencesExist) {
            $data = new FormDataPart([
                'input' => DataPart::fromPath($pdf, 'r'),
                'includeRawCitations' => '1',
                'consolidateCitations' => '1',
            ]);
            $response = $this->client->request('POST', $this->grobidUrl, [
                'headers' => $data->getPreparedHeaders()->toArray(),
                'body' => $data->bodyToIterable(),
            ])->getContent();
            $references = $this->tei->getReferencesInTei($response);
            $this->putGrobidReferencesInCache("6816.pdf",$response);
        }else{
            $references = $this->tei->getReferencesInTei($referencesExist);
        }
        $this->tei->insertReferencesInDB($references,'6816',PaperReferences::SOURCE_METADATA_GROBID);
    }

    public function putGrobidReferencesInCache($name, $response) {
        $cache = new FilesystemAdapter('grobidReferences',0,$this->cacheFolder);
        try {
            $sets = $cache->getItem($name);
        } catch (InvalidArgumentException $e) {
            return;
        }
        if (!$sets->isHit()) {
            $sets->set($response);
            $cache->save($sets);
        }
    }
    public function getGrobidReferencesInCache($name) {

        $cache = new FilesystemAdapter('grobidReferences',0,$this->cacheFolder);

        try {
            $sets = $cache->getItem($name);
        } catch (InvalidArgumentException $e) {
            return;
        }
        if (!$sets->isHit()) {
            return false;
        }
        return $sets->get();
    }
    public function getGrobidReferencesFromDB($docId) {
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['docid' => $docId]);
    }
}