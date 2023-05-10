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
               if ( isset($paperReference['accepted']) && $paperReference['accepted'] !== "0") {
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
        $orderRefArray = explode(";",$form['orderRef']);
        foreach ($orderRefArray as $order => $pkRef) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($pkRef);
            if (!is_null($ref)) {
                $ref->setReferenceOrder($order);
                $this->entityManager->persist($ref);
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @param int $docId
     * @return string|array
     * @throws \JsonException
     */
    public function getReferences(int $docId): array
    {
        $references = $this->grobid->getGrobidReferencesFromDB($docId);
        $rawReferences = [];
        /** @var PaperReferences $references,$reference */
        foreach ($references as $reference) {
            /** @var PaperReferences $reference */
            foreach ($reference->getReference() as $allReferences) {
                $rawReferences[$reference->getId()]['ref'] = $allReferences;
            }
            $rawReferences[$reference->getId()]['isAccepted'] = $reference->getAccepted();
            $rawReferences[$reference->getId()]['referenceOrder'] = $reference->getReferenceOrder();
        }
        return $rawReferences;
    }

    /**
     * @param $docId
     * @return Document
     */
    public function getDocument($docId): Document
    {
        return $this->entityManager->getRepository(Document::class)->find($docId);
    }

    public function UpdateOrderByIdRef(array $idRefs): bool
    {
        foreach ($idRefs as $order => $idRef){
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find(['id'=> $idRef]);
            if (!is_null($ref)){
                $ref->setReferenceOrder($order);
                $ref->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($ref);
            } else {
                return false;
            }
        }
        $this->entityManager->flush();
        return true;
    }

    public function filterOnlyAcceptedRef(array $referencesArray): array
    {
        return array_filter($referencesArray, static function ($var) {
            return ($var['isAccepted'] === 1);
        });
    }
    public function filterReferenceForService(array $referencesArray): array
    {
        return $this->filterOnlyAcceptedRef($referencesArray);

    }
}