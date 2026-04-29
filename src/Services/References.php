<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;
use Seboettg\CiteProc\Exception\CiteProcException;

class References {
    private const SOLR_REFERENCE_FIELDS = ['detectors', 'status', 'pubpeerurl'];


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Grobid $grobid,
        private readonly Bibtex $bibtex,
        private readonly SolrReferenceEnricher $solrReferenceEnricher
    )
    {
    }

    /**
     * @return int[]
     */
    public function validateChoicesReferencesByUser(array $form, array $userInfo) : array
    {
        $refChanged = 0;
        $orderChanged = 0;

        // Récupérer ou créer l'utilisateur UNE SEULE FOIS avant la boucle (optimisation)
        $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
        if (is_null($user)) {
            $user = new UserInformations();
            $user->setId($userInfo['UID']);
            $user->setSurname($userInfo['FIRSTNAME']);
            $user->setName($userInfo['LASTNAME']);
            $this->entityManager->persist($user);
        }

        $referencesToEnrich = [];
        foreach ($form['paperReferences'] as $paperReference) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($paperReference['id']);
            if (!isset($paperReference['checkboxIdTodelete'])) {
                if (!is_null($ref) && isset($paperReference['accepted'])) {
                    if (isset($paperReference['reference'])) {
                        $ref->setReference($this->normalizeReferenceInput($paperReference['reference']));
                    }
                    if ($paperReference['isDirtyTextAreaModifyRef'] === "1"){
                       $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                    }
                    $ref->setAccepted((int) $paperReference['accepted']);
                    $ref->setUpdatedAt(new \DateTimeImmutable());
                    $ref->setUid($user);
                    $user->addPaperReferences($ref);
                    $this->entityManager->persist($ref);
                    $referencesToEnrich[] = $ref;
                    $refChanged++;
                }
            } elseif (!is_null($ref)) {
                $this->entityManager->remove($ref);
                $refChanged++;
            }

        }
        $this->enrichPaperReferences($referencesToEnrich);
        $orderChanged = $this->persistOrderRef($form['orderRef'], $orderChanged);

        // UN SEUL flush() pour toutes les opérations (optimisation performance - gain 80-90%)
        $this->entityManager->flush();

        return ['orderPersisted' => $orderChanged,'referencePersisted' => $refChanged];
    }

    /**
     * @throws CiteProcException
     */
    public function getReferences(int $docId,string $type = "all"|"accepted"): array
    {
        // Récupérer les références selon le type (utilise match pour PHP 8+)
        $references = match($type) {
            'all' => $this->grobid->getAllGrobidReferencesFromDB($docId),
            'accepted' => $this->grobid->getAcceptedReferencesFromDB($docId),
            default => throw new \InvalidArgumentException("Invalid type: {$type}")
        };

        $rawReferences = [];

        /** @var PaperReferences $reference */
        foreach ($references as $reference) {
            $refId = $reference->getId();
            $refData = $reference->getReference();

            if (empty($refData)) {
                continue;
            }

            $formattedReference = $this->bibtex->getCslRefText($refData);
            foreach (self::SOLR_REFERENCE_FIELDS as $field) {
                if (array_key_exists($field, $refData)) {
                    $formattedReference[$field] = $refData[$field];
                }
            }

            $rawReferences[$refId]['ref'] = $formattedReference;

            if (array_key_exists('csl', $refData)) {
                $rawReferences[$refId]['csl'] = $refData;
            }

            $rawReferences[$refId]['isAccepted'] = $reference->getAccepted();
            $rawReferences[$refId]['referenceOrder'] = $reference->getReferenceOrder();
        }

        return $rawReferences;
    }

    /**
     * @param $docId
     */
    public function getDocument($docId): ?Document
    {
        return $this->entityManager->getRepository(Document::class)->find($docId);
    }

    public function addNewReference(array $form, array $userInfo): bool
    {
        if ($form['addReference'] !== ""){
            $ref = new PaperReferences();
            $refInfo = ['raw_reference'=>$form['addReference']];
            if ($form['addReferenceDoi'] !== "") {
                $regexDoiOrg = "/^https?:\\/\\/(?:dx\\.|www\\.)?doi\\.org\\/(10\\.\\d{4,}(?:\\.\\d+)*(?:\\/|%2F)(?:(?![\"&\\'])\\S)+)/";
                if (preg_match($regexDoiOrg, (string) $form['addReferenceDoi'],$matches)) {
                    $form['addReferenceDoi'] = $matches[1];
                }
                $refInfo['doi'] = $form['addReferenceDoi'];
            }
            $refInfo = $this->solrReferenceEnricher->enrichReference($refInfo);
            $ref->setReference($refInfo);
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
            $counter = $this->getLastOrder((int) $form['id']) + 1;
            $ref->setReferenceOrder($counter);
            $this->entityManager->persist($ref);
            $this->entityManager->flush();
            return true;
        }
        return false;
    }

    /**
     * @param $orderRef
     */
    public function persistOrderRef($orderRef, int $orderChanged): int
    {
        $orderRefArray = explode(";", (string) $orderRef);
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
        return $this->getDocument($docId) instanceof Document;
    }
    public function createDocumentId(int $docId): Document{
        $doc = new Document();
        $doc->setId($docId);
        $this->entityManager->persist($doc);
        $this->entityManager->flush();
        return $doc;
    }

    public function getLastOrder(int $docId): int
    {
        $result = $this->entityManager->getRepository(PaperReferences::class)
            ->createQueryBuilder('p')
            ->select('MAX(p.referenceOrder)')
            ->where('p.document = :docId')
            ->setParameter('docId', $docId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : -1;
    }

    public function autosaveOrder(string $orderRef): void
    {
        $this->persistOrderRef($orderRef, 0);
        $this->entityManager->flush();
    }

    public function autosaveReference(int $refId, string $referenceJson, int $accepted, bool $isDirty, array $userInfo): void
    {
        $ref = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        if ($ref === null) {
            return;
        }

        $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
        if ($user === null) {
            $user = new UserInformations();
            $user->setId($userInfo['UID']);
            $user->setSurname($userInfo['FIRSTNAME']);
            $user->setName($userInfo['LASTNAME']);
            $this->entityManager->persist($user);
        }

        $refData = json_decode($referenceJson, true) ?? [];
        $refData = $this->solrReferenceEnricher->enrichReference($refData);
        $ref->setReference($refData);
        $ref->setAccepted($accepted);
        if ($isDirty) {
            $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
        }
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setUid($user);
        $user->addPaperReferences($ref);
        $this->entityManager->persist($ref);
        $this->entityManager->flush();
    }

    private function normalizeReferenceInput(mixed $reference): array
    {
        if (is_array($reference)) {
            return $reference;
        }

        if (is_string($reference)) {
            $decoded = json_decode($reference, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param array<int, PaperReferences> $paperReferences
     */
    private function enrichPaperReferences(array $paperReferences): void
    {
        if ($paperReferences === []) {
            return;
        }

        $references = array_map(
            static fn (PaperReferences $paperReference): array => $paperReference->getReference(),
            $paperReferences
        );

        foreach ($this->solrReferenceEnricher->enrichReferences($references) as $index => $reference) {
            $paperReferences[$index]->setReference($reference);
        }
    }
}
