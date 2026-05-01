<?php

namespace App\Services;

use App\Entity\PaperReferences;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Psr\Log\LoggerInterface;

class Grobid {

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Tei $tei,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $grobidUrl,
        private readonly CacheItemPoolInterface $grobidCache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function insertReferences(int $docId, string $pathPdf): bool
    {
        $referencesExist = $this->getGrobidReferencesInCache($docId.".pdf");
        if (!$referencesExist) {
            $data = new FormDataPart([
                'input' => DataPart::fromPath($pathPdf, 'r'),
                'includeRawCitations' => '1',
                'consolidateCitations' => '1',
            ]);
            try {
                $response = $this->client->request('POST', $this->grobidUrl, [
                    'headers' => $data->getPreparedHeaders()->toArray(),
                    'body' => $data->bodyToIterable(),
                ])->getContent();
            } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
                $this->logger->error('GROBID request failed', ['docId' => $docId, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
                return false;
            }
            $references = $this->tei->getReferencesInTei($response);
            if ($references === []) {
                return false;
            }
            $this->putGrobidReferencesInCache($docId.".pdf", $response);
        } else {
            $references = $this->tei->getReferencesInTei($referencesExist);
        }
        $this->tei->insertReferencesInDB($references, $docId, PaperReferences::SOURCE_METADATA_GROBID);
        return true;
    }

    public function putGrobidReferencesInCache(string $name, mixed $response): void
    {
        try {
            $item = $this->grobidCache->getItem($name);
            if (!$item->isHit()) {
                $item->set($response);
                $this->grobidCache->save($item);
            }
        } catch (InvalidArgumentException) {
            return;
        }
    }

    public function getGrobidReferencesInCache(string $name): mixed
    {
        try {
            $item = $this->grobidCache->getItem($name);
            return $item->isHit() ? $item->get() : false;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function hasCachedReferences(int $docId): bool
    {
        return $this->getGrobidReferencesInCache($docId . '.pdf') !== false;
    }

    /**
     * @return PaperReferences[]
     */
    public function getAllGrobidReferencesFromDB(int $docId): array {
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId]);
    }

    /**
     * @return PaperReferences[]
     */
    public function getAcceptedReferencesFromDB(int $docId): array {
        return $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId, 'accepted'=>1], ['referenceOrder'=>'ASC']);

    }

    public function countAllReferencesFromDB(int $docId): int
    {
        return $this->entityManager->getRepository(PaperReferences::class)->count(['document' => $docId]);
    }
}