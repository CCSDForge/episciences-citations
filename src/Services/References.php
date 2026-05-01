<?php
namespace App\Services;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Seboettg\CiteProc\Exception\CiteProcException;

class References {
    private const array SOLR_REFERENCE_FIELDS = ['detectors', 'status', 'pubpeerurl'];


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Grobid $grobid,
        private readonly Bibtex $bibtex,
        private readonly SolrReferenceEnricher $solrReferenceEnricher,
        private readonly LoggerInterface $logger
    )
    {
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $userInfo
     * @return array{orderPersisted: int, referencePersisted: int}
     */
    public function validateChoicesReferencesByUser(array $form, array $userInfo) : array
    {
        $refChanged = 0;
        $orderChanged = 0;

        // Récupérer ou créer l'utilisateur UNE SEULE FOIS avant la boucle (optimisation)
        $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID'] ?? null);
        if (is_null($user) && isset($userInfo['UID'])) {
            $user = new UserInformations();
            $user->setId((int) $userInfo['UID']);
            $user->setSurname($userInfo['FIRSTNAME'] ?? '');
            $user->setName($userInfo['LASTNAME'] ?? '');
            $this->entityManager->persist($user);
        }

        if (is_null($user)) {
             return ['orderPersisted' => 0, 'referencePersisted' => 0];
        }

        $referencesToEnrich = [];
        $paperReferences = $form['paperReferences'] ?? [];
        foreach ($paperReferences as $paperReference) {
            $ref = $this->entityManager->getRepository(PaperReferences::class)->find($paperReference['id']);
            if (!isset($paperReference['checkboxIdTodelete'])) {
                if (!is_null($ref) && isset($paperReference['accepted'])) {
                    if (isset($paperReference['reference'])) {
                        $ref->setReference($this->normalizeReferenceInput($paperReference['reference']));
                    }
                    if ($paperReference['isDirtyTextAreaModifyRef'] === "1"){
                       $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                    }
                    if (isset($paperReference['accepted']) && $paperReference['accepted'] !== '') {
                        $newAccepted = (int) $paperReference['accepted'];
                        if ($ref->getAccepted() !== $newAccepted) {
                            $this->logger->info('Updating reference accepted state', ['id' => $ref->getId(), 'old' => $ref->getAccepted(), 'new' => $newAccepted]);
                            $ref->setAccepted($newAccepted);
                            $refChanged++;
                        }
                    } elseif (is_null($ref->getAccepted())) {
                        $this->logger->info('Initializing null accepted state to 0', ['id' => $ref->getId()]);
                        $ref->setAccepted(0);
                        $refChanged++;
                    }

                    if (isset($paperReference['accepted'])) {
                        $ref->setUpdatedAt(new \DateTimeImmutable());
                        $ref->setUid($user);
                        $user->addPaperReferences($ref);
                        $this->entityManager->persist($ref);
                        $referencesToEnrich[] = $ref;
                    }
                }
            } elseif (!is_null($ref)) {
                $this->entityManager->remove($ref);
                $refChanged++;
            }

        }
        $this->enrichPaperReferences($referencesToEnrich);
        $orderChanged = $this->persistOrderRef($form['orderRef'] ?? '', $orderChanged);

        // UN SEUL flush() pour toutes les opérations (optimisation performance - gain 80-90%)
        $this->entityManager->flush();

        return ['orderPersisted' => $orderChanged,'referencePersisted' => $refChanged];
    }

    /**
     * @param 'all'|'accepted' $type
     * @return array<int, array<string, mixed>>
     * @throws CiteProcException
     */
    public function getReferences(int $docId, string $type = 'all'): array
    {
        // Récupérer les références selon le type (utilise match pour PHP 8+)
        $references = match($type) {
            'all' => $this->grobid->getAllGrobidReferencesFromDB($docId),
            'accepted' => $this->grobid->getAcceptedReferencesFromDB($docId),
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

    public function getDocument(int $docId): ?Document
    {
        return $this->entityManager->getRepository(Document::class)->find($docId);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $userInfo
     */
    public function addNewReference(array $form, array $userInfo): bool
    {
        $addReference = $form['addReference'] ?? "";
        if ($addReference !== ""){
            $ref = new PaperReferences();
            $refInfo = ['raw_reference'=>$addReference];
            $addReferenceDoi = $form['addReferenceDoi'] ?? "";
            if ($addReferenceDoi !== "") {
                $regexDoiOrg = "/^https?:\\/\\/(?:dx\\.|www\\.)?doi\\.org\\/(10\\.\\d{4,}(?:\\.\\d+)*(?:\\/|%2F)(?:(?![\"&\\'])\\S)+)/";
                if (preg_match($regexDoiOrg, (string) $addReferenceDoi,$matches)) {
                    $addReferenceDoi = $matches[1];
                }
                $refInfo['doi'] = $addReferenceDoi;
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
            $docId = (int) ($form['id'] ?? 0);
            $ref->setDocument($this->entityManager->getRepository(Document::class)->find($docId));
            $counter = $this->getLastOrder($docId) + 1;
            $ref->setReferenceOrder($counter);
            $this->entityManager->persist($ref);
            $this->entityManager->flush();
            return true;
        }
        return false;
    }

    public function persistOrderRef(string $orderRef, int $orderChanged): int
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

    public function documentAlreadyExtracted(int $docId): bool {
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

    /**
     * @param array<string, mixed> $userInfo
     * @return array<string, mixed>
     */
    public function autosaveReference(int $refId, string $referenceJson, int $accepted, bool $isDirty, array $userInfo): array
    {
        $ref = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        if ($ref === null) {
            return [];
        }

        $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID'] ?? null);
        if ($user === null && isset($userInfo['UID'])) {
            $user = new UserInformations();
            $user->setId((int) $userInfo['UID']);
            $user->setSurname($userInfo['FIRSTNAME'] ?? '');
            $user->setName($userInfo['LASTNAME'] ?? '');
            $this->entityManager->persist($user);
        }

        if ($user === null) {
             // Fallback or handle error if UID is missing
             return [];
        }

        $refData = json_decode($referenceJson, true) ?? [];
        $refData = $this->solrReferenceEnricher->enrichReference($refData);
        $ref->setReference($refData);
        
        if ($ref->getAccepted() !== $accepted) {
            $this->logger->info('Autosave: Updating accepted state', ['id' => $refId, 'old' => $ref->getAccepted(), 'new' => $accepted]);
            $ref->setAccepted($accepted);
        }

        if ($isDirty) {
            $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
        }
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setUid($user);
        $user->addPaperReferences($ref);
        $this->entityManager->persist($ref);
        $this->entityManager->flush();

        return $refData;
    }

    /**
     * @return array<string, mixed>
     */
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
