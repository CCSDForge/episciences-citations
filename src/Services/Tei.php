<?php
namespace App\Services;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;

class Tei {

    public function __construct(private EntityManagerInterface $entityManager)
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
                $info[] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        return $info;
    }

    public function insertReferencesInDB(array $references, int $docId, string $source): void
    {
        $this->removeAllRefGrobidSource($docId);
        foreach ($references as $orderRef => $reference) {
            $refs = new PaperReferences();
            $refs->setReference((array)($reference));
            $refs->setDocid($docId);
            $refs->setSource($source);
            $refs->setUpdatedAt(new \DateTimeImmutable());
            $refs->setReferenceOrder($orderRef);
            $refs->setUid('1111111');
            $this->entityManager->persist($refs);
        }
        $this->entityManager->flush();
    }
    private function removeAllRefGrobidSource(int $docId): void
    {
        $refs = $this->entityManager->getRepository(PaperReferences::class)->findBy(
            [
                'docid' => $docId,
                'source' => PaperReferences::SOURCE_METADATA_GROBID
            ]);
        if (!empty($refs)){
            foreach ($refs as $ref){
                $this->entityManager->remove($ref);
            }

        }
        $this->entityManager->flush();
    }
}