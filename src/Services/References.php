<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;

class References {

    public function __construct(private EntityManagerInterface $entityManager,private Grobid $grobid, private Bibtex $bibtex)
    {
    }

    /**
     * @param array $form
     * @param array $userInfo
     * @return int[]
     */
    public function validateChoicesReferencesByUser(array $form, array $userInfo) : array
    {
        $refChanged = 0;
        $orderChanged = 0;
        foreach ($form['paperReferences'] as $paperReference) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($paperReference['id']);
            if (!isset($paperReference['checkboxIdTodelete'])) {
                $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
                if (is_null($user)) {
                    $user = new UserInformations();
                    $user->setId($userInfo['UID']);
                    $user->setSurname($userInfo['FIRSTNAME']);
                    $user->setName($userInfo['LASTNAME']);
                }
                if (!is_null($ref)) {
                    if (isset($paperReference['accepted'])) {
                        if ($paperReference['isDirtyTextAreaModifyRef'] === "1"){
                           $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                        }
                        $ref->setAccepted($paperReference['accepted']);
                        $ref->setUpdatedAt(new \DateTimeImmutable());
                        $ref->setUid($user);
                        $user->addPaperReferences($ref);
                        $this->entityManager->persist($ref);
                        $refChanged++;
                    }
                    $this->entityManager->flush();
                }
            } else {
                $this->entityManager->remove($ref);
                $this->entityManager->flush();
                $refChanged++;
            }

        }
        $orderChanged = $this->persistOrderRef($form['orderRef'], $orderChanged);
        $this->entityManager->flush();
        return ['orderPersisted' => $orderChanged,'referencePersisted' => $refChanged];
    }

    /**
     * @param int $docId
     * @return string|array
     * @throws \JsonException
     */
    public function getReferences(int $docId,string $type = "all"|"accepted"): array
    {
        if ($type === "all"){
            $references = $this->grobid->getAllGrobidReferencesFromDB($docId);
        }
        if ($type === 'accepted'){
            $references = $this->grobid->getAcceptedReferencesFromDB($docId);
        }
        $rawReferences = [];
        /** @var PaperReferences $references,$reference */
        foreach ($references as $reference) {
            /** @var PaperReferences $reference */
            foreach ($reference->getReference() as $allReferences) {
                $rawReferences[$reference->getId()]['ref'] = $allReferences;
                $rawReferences[$reference->getId()]['ref'] = $this->bibtex->getCslRefText($allReferences);
            }
            $rawReferences[$reference->getId()]['isAccepted'] = $reference->getAccepted();
            $rawReferences[$reference->getId()]['referenceOrder'] = $reference->getReferenceOrder();
        }
        return $rawReferences;
    }

    /**
     * @param $docId
     * @return Document|null
     */
    public function getDocument($docId): ?Document
    {
        return $this->entityManager->getRepository(Document::class)->find($docId);
    }

    /**
     * @throws \JsonException
     */
    public function addNewReference(array $form, array $userInfo): bool
    {
        if ($form['addReference'] !== ""){
            $ref = new PaperReferences();
            $refInfo = ['raw_reference'=>$form['addReference']];
            if ($form['addReferenceDoi'] !== "") {
                $regexDoiOrg = "/^https?:\/\/(?:dx\.|www\.)?doi\.org\/(10\.[0-9]{4,}(?:\.[0-9]+)*(?:\/|%2F)(?:(?![\"&\'])\S)+)/";
                if (preg_match($regexDoiOrg, $form['addReferenceDoi'],$matches)) {
                    $form['addReferenceDoi'] = $matches[1];
                }
                $refInfo['doi'] = $form['addReferenceDoi'];
            }
            $ref->setReference([json_encode($refInfo,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
            $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
            $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
            if (is_null($user)) {
                $user = new UserInformations();
                $user->setId($userInfo['UID']);
                $user->setSurname($userInfo['FIRSTNAME']);
                $user->setName($userInfo['LASTNAME']);
            }
            $ref->setUid($user);
            $ref->setAccepted(1);
            $ref->setUpdatedAt(new \DateTimeImmutable());
            $ref->setDocument($this->entityManager->getRepository(Document::class)->find($form['id']));
            $counter = isset($form['paperReferences']) ? $this->getLastOrder($form['paperReferences']) + 1 : 0 ;
            $ref->setReferenceOrder($counter);
            $this->entityManager->persist($ref);
            $this->entityManager->flush();
            return true;
        }
        return false;
    }

    /**
     * @param $orderRef
     * @param int $orderChanged
     * @return int
     */
    public function persistOrderRef($orderRef, int $orderChanged): int
    {
        $orderRefArray = explode(";", $orderRef);
        foreach ($orderRefArray as $order => $pkRef) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($pkRef);
            if (!is_null($ref)) {
                $ref->setReferenceOrder($order);
                $this->entityManager->persist($ref);
                $orderChanged++;
            }

        }
        return $orderChanged;
    }

    public function documentAlreadyExtracted($docId): bool {
        return $this->getDocument($docId) !== null;
    }
    public function createDocumentId($docId){
        $doc = new Document();
        $doc->setId($docId);
        $this->entityManager->persist($doc);
        $this->entityManager->flush();
        return $doc;
    }

    public function getLastOrder(array $paperReferences){
        $paperReferences = array_column($paperReferences, 'reference_order');
        sort($paperReferences);
        return $paperReferences[array_key_last($paperReferences)];
    }
}