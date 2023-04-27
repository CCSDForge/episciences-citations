<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\Documents;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use App\Repository\PaperReferencesRepository;
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
                $info[] = json_encode($raw_reference, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
        }
        return $info;
    }

    public function insertReferencesInDB(array $references, int $docId, string $source): void
    {
        $this->removeAllRefGrobidSource($docId);
        $docExisting = $this->documentRepository->find($docId);
        if (is_null($docExisting)){
            $doc = new Document();
            $doc->setId($docId);
        }
        foreach ($references as $orderRef => $reference) {
            $refs = new PaperReferences();
            $refs->setReference((array)($reference));
            $refs->setSource($source);
            $refs->setUpdatedAt(new \DateTimeImmutable());
            $refs->setReferenceOrder($orderRef);
            $refs->setIsArchived(false);
            if (is_null($docExisting)){
                $refs->setDocument($doc);
                $doc->addPaperReference($refs);
            } else {
                $refs->setDocument($docExisting);
                $docExisting->addPaperReference($refs);
            }
            $this->entityManager->persist($refs);
        }
        $this->entityManager->flush();
    }
    private function removeAllRefGrobidSource(int $docId): void
    {
        $refs = $this->entityManager->getRepository(PaperReferences::class)->findBy(['document' => $docId]);
        if (!empty($refs)){
            foreach ($refs as $ref){
                if (($ref->getAccepted() === 0 || is_null($ref->getAccepted()))){
                    $this->entityManager->remove($ref);
                }
            }

        }
        $this->entityManager->flush();
    }
}