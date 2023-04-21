<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;

class References {

    public function __construct(private EntityManagerInterface $entityManager,private Grobid $grobid)
    {
    }

    public function validateChoicesReferencesByUser(array $form, string $uid) : void
    {
        foreach ($form['paperReferences'] as $paperReference) {
               $ref = $this->entityManager->getRepository(PaperReferences::class)->find($paperReference['id']);
               if (!is_null($ref)) {
                   if ($paperReference['accepted'] !== 0) {
                       $ref->setAccepted($paperReference['accepted']);
                       $ref->setUid($uid);
                       $ref->setUpdatedAt(new \DateTimeImmutable());
                    }
                    $this->entityManager->flush();
           }
//
        }
//        $row = 0;
//        if (array_key_exists("choice",$form)){
//            foreach ($form['choice'] as $choiceRef) {
//                $ref = $this->entityManager->getRepository(PaperReferences::class)->findOneBy(['id' => $choiceRef]);
//                if (!is_null($ref) && $ref->getSource() !== PaperReferences::SOURCE_METADATA_EPI_USER) {
//                    $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
//                    $ref->setUpdatedAt(new \DateTimeImmutable());
//                    $ref->setUid($uid);
//                    $ref->setAccepted(1);
//                    $this->entityManager->flush();
//                    ++$row;
//                }
//            }
//        }
//        return $row;
    }

    /**
     * @param int $docId
     * @param string $format
     * @return string|array
     * @throws \JsonException
     */
    public function getReferences(int $docId,string $format = "json"|"array"): string|array
    {
        $references = $this->grobid->getGrobidReferencesFromDB($docId);
        $rawReferences = [];
        /** @var PaperReferences $references,$reference */
        foreach ($references as $reference) {
            foreach ($reference->getReference() as $allReferences) {
                $rawReferences['ref'][$reference->getId()] = ($format === 'json') ?
                    $allReferences :
                    json_decode($allReferences, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return ($format === 'json') ? json_encode($rawReferences, JSON_THROW_ON_ERROR) : $rawReferences;
    }

    /**
     * @param $docId
     * @return Document
     */
    public function getDocument($docId): Document
    {
        return $this->entityManager->getRepository(Document::class)->find($docId);
    }
}