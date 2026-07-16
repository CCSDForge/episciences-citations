<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

class Tei
{

    public function __construct(private readonly EntityManagerInterface $entityManager,
                                private readonly DocumentRepository $documentRepository,
                                private readonly SolrReferenceEnricher $solrReferenceEnricher)
    {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getReferencesInTei(string $tei): array
    {
        $xml = simplexml_load_string($tei);
        if ($xml === false) {
            return [];
        }

        $info = [];
        foreach ($xml->text as $teInfo) {
            foreach ($teInfo->back->div->listBibl->biblStruct as $value) {
                $info[] = $this->extractReferenceFromBiblStruct($value);
            }
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function extractReferenceFromBiblStruct(\SimpleXMLElement $value): array
    {
        $rawReference = $this->extractRawReferenceNote($value);

        $doi = $this->extractDoi($value);
        if ($doi !== null) {
            $rawReference['doi'] = $doi;
        }

        return $rawReference;
    }

    /**
     * @return array<string, string>
     */
    private function extractRawReferenceNote(\SimpleXMLElement $value): array
    {
        $rawReference = [];
        foreach ($value->note as $note) {
            if (!is_null($note->attributes()) && (string) $note->attributes() === 'raw_reference') {
                $rawReference['raw_reference'] = (string) $note;
            }
        }

        return $rawReference;
    }

    private function extractDoi(\SimpleXMLElement $value): ?string
    {
        if ($value->analytic && $value->analytic->idno && (string) $value->analytic->idno->attributes() === 'DOI') {
            return (string) $value->analytic->idno;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $references
     */
    public function insertReferencesInDB(array $references, int $docId, string $source): void
    {
        $this->removeAllRefGrobidSource($docId);
        $docExisting = $this->documentRepository->find($docId);
        $referenceAlreadyAcceptedByUser = [];
        $counterRef = 0;
        if ($docExisting !== null) {
            $reOrdonateCounter = 0;
            foreach ($docExisting->getPaperReferences() as $doc) {
                $doc->setReferenceOrder($reOrdonateCounter);
                $referenceAlreadyAcceptedByUser[] = serialize($doc->getReference());
                $this->entityManager->persist($doc);
                $reOrdonateCounter++;
                $counterRef++;
            }
            $this->entityManager->flush();
        }
        if (is_null($docExisting)) {
            $doc = new Document();
            $doc->setId($docId);
        }
        foreach ($this->solrReferenceEnricher->enrichReferences($references) as $reference) {
            if (!in_array(serialize($reference), $referenceAlreadyAcceptedByUser, true)) {
                $refs = new PaperReferences();
                $refs->setReference($reference);
                $refs->setSource($source);
                $refs->setAccepted(0);
                $refs->setUpdatedAt(new \DateTimeImmutable());
                $refs->setReferenceOrder($counterRef);
                if (is_null($docExisting)) {
                    $refs->setDocument($doc);
                    $doc->addPaperReference($refs);
                } else {
                    $refs->setDocument($docExisting);
                    $docExisting->addPaperReference($refs);
                }
                $this->entityManager->persist($refs);
            }
            $counterRef++;
        }
        $this->entityManager->flush();
    }

    private function removeAllRefGrobidSource(int $docId): void
    {
        $refs = $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId]);
        foreach ($refs as $ref) {
            if ($ref->getAccepted() === 0 || is_null($ref->getAccepted())) {
                $this->entityManager->remove($ref);
            }
        }
        $this->entityManager->flush();
    }
}
