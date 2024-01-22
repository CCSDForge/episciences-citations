<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

class Tei {

    public function __construct(private EntityManagerInterface $entityManager,private DocumentRepository $documentRepository)
    {
    }

    /**
     * @param $tei
     * @return array
     * @throws \JsonException
     */
    public function getReferencesInTei($tei): array
    {

        $tei = simplexml_load_string($tei);
        $info = [];
        if ($tei !== false) {
            foreach ($tei->text as $teInfo) {
                foreach ($teInfo->back->div->listBibl->biblStruct as $value) {
                    $raw_reference = [];
                    foreach ($value->note as $note) {
                        if (!is_null($note->attributes()) && (string) $note->attributes() === 'raw_reference') {
                            $raw_reference['raw_reference'] = (string) $note;
                        }
                    }

                    if ($value->analytic && $value->analytic->idno && (string) $value->analytic->idno->attributes() === 'DOI') {
                        $raw_reference['doi'] = (string) $value->analytic->idno;
                    }
                    $info[] = json_encode($raw_reference, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
        if ($docExisting !== null){
            foreach ($docExisting->getPaperReferences() as $doc){
                $referenceAlreadyAcceptedByUser[] = serialize(json_decode($doc->getReference()[0], true, 512, JSON_THROW_ON_ERROR));
            }
        }
        if (is_null($docExisting)){
            $doc = new Document();
            $doc->setId($docId);
        }
        foreach ($references as $orderRef => $reference) {
            if (!in_array(serialize(json_decode($reference, true, 512, JSON_THROW_ON_ERROR)),$referenceAlreadyAcceptedByUser,true)){
                $refs = new PaperReferences();
                $refs->setReference((array)($reference));
                $refs->setSource($source);
                $refs->setUpdatedAt(new \DateTimeImmutable());
                $refs->setReferenceOrder($orderRef);
                if (is_null($docExisting)){
                    $refs->setDocument($doc);
                    $doc->addPaperReference($refs);
                } else {
                    $refs->setDocument($docExisting);
                    $docExisting->addPaperReference($refs);
                }
                $this->entityManager->persist($refs);
            }
        }
        $this->entityManager->flush();
    }
    private function removeAllRefGrobidSource(int $docId): void
    {
        $refs = $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId]);
        if (!empty($refs)) {
            foreach ($refs as $ref) {
                if ($ref->getAccepted() === 0 || is_null($ref->getAccepted())){
                    $this->entityManager->remove($ref);
                }
            }

        }
        $this->entityManager->flush();
    }
}