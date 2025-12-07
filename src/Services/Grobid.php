<?php

namespace App\Services;

use App\Entity\PaperReferences;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Grobid {

    public function __construct(
        private HttpClientInterface $client,
        private Tei $tei,
        private EntityManagerInterface $entityManager,
        private string $cacheFolder,
        private string $grobidUrl,
        private CacheInterface $grobidCache
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws \JsonException
     */
    public function insertReferences(int $docId,string $pathPdf): bool
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
            if (empty($references)){
                return false;
            }
            $this->putGrobidReferencesInCache($docId.".pdf",$response);
        }else{
            $references = $this->tei->getReferencesInTei($referencesExist);
        }
        $this->tei->insertReferencesInDB($references,$docId,PaperReferences::SOURCE_METADATA_GROBID);
        return true;
    }

    /**
     * @param $name
     * @param $response
     * @return void
     */
    public function putGrobidReferencesInCache($name, $response): void
    {
        try {
            $item = $this->grobidCache->getItem($name);
            if (!$item->isHit()) {
                $item->set($response);
                $this->grobidCache->save($item);
            }
        } catch (InvalidArgumentException $e) {
            return;
        }
    }

    /**
     * @param $name
     * @return false|mixed
     */
    public function getGrobidReferencesInCache($name): mixed
    {
        try {
            $item = $this->grobidCache->getItem($name);
            return $item->isHit() ? $item->get() : false;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @param $docId
     * @return PaperReferences[]|array|object[]
     */
    public function getAllGrobidReferencesFromDB($docId) {
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId]);
    }
    public function getAcceptedReferencesFromDB($docId){
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId, 'accepted'=>1], ['referenceOrder'=>'ASC']);

    }
}