<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;

class References {

    public function __construct(private EntityManagerInterface $entityManager,private Grobid $grobid)
    {
    }

    public function validateChoicesReferencesByUser(array $form, array $userInfo) : void
    {

        foreach ($form['paperReferences'] as $paperReference) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($paperReference['id']);
            $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
            if (is_null($user)) {
                $user = new UserInformations();
                $user->setId($userInfo['UID']);
                $user->setSurname($userInfo['FIRSTNAME']);
                $user->setName($userInfo['LASTNAME']);
            }
            if (!is_null($ref)) {
               if ($paperReference['accepted'] !== "0") {
                   $ref->setAccepted($paperReference['accepted']);
                   $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                   $ref->setUpdatedAt(new \DateTimeImmutable());
                   $ref->setUid($user);
                   $user->addPaperReferences($ref);
                   $this->entityManager->persist($ref);
                }
                $this->entityManager->flush();
            }
        }
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

    /**
     * @param int $docId
     * @param int $refId
     * @param int $uid
     * @return bool
     */
    public function archiveReference(int $docId,int $refId,int $uid): bool
    {
        $ref = $this->entityManager->getRepository(PaperReferences::class)->findBy(['id'=>$refId,'document'=>$docId])    ;
        $user = $this->entityManager->getRepository(UserInformations::class)->find($uid);
        if (!empty($ref)) {
            foreach ($ref as $info){
                $info->setIsArchived(true);
                //todo check if user exist
                $info->setUid($user);
                $info->setUpdatedAt(new \DateTimeImmutable());
                $info->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                $this->entityManager->persist($info);
            }
            $this->entityManager->flush();
            return true;
        }
        return false;
    }
}