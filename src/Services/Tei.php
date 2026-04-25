<?php

namespace App\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

class Tei
{

    public function __construct(private readonly EntityManagerInterface $entityManager,
                                private readonly DocumentRepository $documentRepository)
    {
    }

    /**
     * @param $tei
     * @return array<int, array<string, string>>
     */
    public function getReferencesInTei($tei): array
    {
        $tei = simplexml_load_string((string) $tei);
        $info = [];
        if ($tei !== false) {
            foreach ($tei->text as $teInfo) {
                foreach ($teInfo->back->div->listBibl->biblStruct as $value) {
                    $raw_reference = [];
                    foreach ($value->note as $note) {
                        if (!is_null($note->attributes()) && (string)$note->attributes() === 'raw_reference') {
                            $raw_reference['raw_reference'] = (string)$note;
                        }
                    }

                    if ($value->analytic && $value->analytic->idno &&
                        (string)$value->analytic->idno->attributes() === 'DOI') {
                        $raw_reference['doi'] = (string)$value->analytic->idno;
                    }
                    $info[] = $raw_reference;
                }
            }
            return $info;
        }
        return [];
    }

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
        foreach ($references as $reference) {
            if (!in_array(serialize($reference), $referenceAlreadyAcceptedByUser, true)) {
                $refs = new PaperReferences();
                $refs->setReference($reference);
                $refs->setSource($source);
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
        if (!empty($refs)) {
            foreach ($refs as $ref) {
                if ($ref->getAccepted() === 0 || is_null($ref->getAccepted())) {
                    $this->entityManager->remove($ref);
                }
            }

        }
        $this->entityManager->flush();
    }
}