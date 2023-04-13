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
    public function insertReferences(int $docId,string $pathPdf): void
    {
        $referencesExist = $this->getGrobidReferencesInCache($docId.".pdf");
        if (!$referencesExist) {
            $data = new FormDataPart([
                'input' => DataPart::fromPath($pathPdf, 'r'),
                'includeRawCitations' => '1',
                'consolidateCitations' => '1',
            ]);
            $response = $this->client->request('POST', $this->grobidUrl, [
                'headers' => $data->getPreparedHeaders()->toArray(),
                'body' => $data->bodyToIterable(),
            ])->getContent();
            $references = $this->tei->getReferencesInTei($response);
            $this->putGrobidReferencesInCache($docId.".pdf",$response);
        }else{
            $references = $this->tei->getReferencesInTei($referencesExist);
        }
        $this->tei->insertReferencesInDB($references,$docId,PaperReferences::SOURCE_METADATA_GROBID);
    }

    /**
     * @param $name
     * @param $response
     * @return void
     */
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

    /**
     * @param $name
     * @return false|mixed|void
     */
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

    /**
     * @param $docId
     * @return PaperReferences[]|array|object[]
     */
    public function getGrobidReferencesFromDB($docId) {
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['docid' => $docId]);
    }
}