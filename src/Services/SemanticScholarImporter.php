<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SemanticScholarImporter
{
    public const string PREFIX_ARXIV = '10.48550/';

    public function __construct(
        private readonly Semanticsscholar       $semanticsscholar,
        private readonly Doi                    $doiService,
        private readonly Bibtex                 $bibtexService,
        private readonly References             $references,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $logger,
        private readonly SolrReferenceEnricher  $solrReferenceEnricher,
    ) {
    }

    /**
     * Fetches references from Semantic Scholar for the given paper ID and inserts them into the DB.
     *
     * @throws \RuntimeException when the paper ID is not found or has no references
     * @throws \JsonException
     */
    public function importByPaperId(string $paperId, int $docId, int $startOrder): int
    {
        $raw = $this->semanticsscholar->getRef($paperId);
        if ($raw === '') {
            throw new \RuntimeException('DOI not found in Semantic Scholar');
        }

        $semanticsRef = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($semanticsRef['data']) || $semanticsRef['data'] === []) {
            throw new \RuntimeException('No references found for this paper ID');
        }

        $this->removeAllS2RefFromDb($docId);

        return $this->processS2Ref($semanticsRef, $startOrder, $docId);
    }

    public function removeAllS2RefFromDb(int $docId): void
    {
        $existing = $this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId, 'source' => PaperReferences::SOURCE_SEMANTICS_SCHOLAR]);

        foreach ($existing as $ref) {
            $this->entityManager->remove($ref);
        }
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $semanticsRef
     */
    private function processS2Ref(array $semanticsRef, int $startOrder, int $docId): int
    {
        $inserted = 0;
        foreach ($semanticsRef['data'] as $rSemantics) {
            if ($this->hasDoi($rSemantics)) {
                $this->insertRefS2fromDoi($rSemantics['citedPaper']['externalIds']['DOI'], $startOrder + $inserted, $docId);
                $inserted++;
            } elseif ($this->hasBibTeX($rSemantics)) {
                if ($this->hasMandatoryBibtexInfo($rSemantics)) {
                    $this->insertCslFromBibtexS2($rSemantics['citedPaper']['citationStyles']['bibtex'], $startOrder + $inserted, $docId);
                    $inserted++;
                } elseif ($this->hasUrlInTitle($rSemantics['citedPaper']['title'])) {
                    $this->insertFromUrlText($rSemantics['citedPaper'], $startOrder + $inserted, $docId);
                    $inserted++;
                }
            } elseif ($this->hasArxiv($rSemantics)) {
                $this->insertRefFromArXivIdS2($rSemantics['citedPaper']['externalIds'], $startOrder + $inserted, $docId);
                $inserted++;
            }
        }
        return $inserted;
    }

    private function hasDoi(mixed $rSemantics): bool
    {
        return isset($rSemantics['citedPaper']['externalIds']['DOI'])
            && !empty($rSemantics['citedPaper']['externalIds'])
            && $rSemantics['citedPaper']['externalIds']['DOI'] !== '';
    }

    private function hasArxiv(mixed $rSemantics): bool
    {
        return !isset($rSemantics['citedPaper']['externalIds']['DOI'])
            && isset($rSemantics['citedPaper']['externalIds']['ArXiv']);
    }

    private function hasBibTeX(mixed $rSemantics): bool
    {
        return !isset($rSemantics['citedPaper']['externalIds']) ||
            (!isset($rSemantics['citedPaper']['externalIds']['DOI']) && !isset($rSemantics['citedPaper']['externalIds']['ArXiv']));
    }

    private function hasMandatoryBibtexInfo(mixed $rSemantics): bool
    {
        return isset(
            $rSemantics['citedPaper']['title'],
            $rSemantics['citedPaper']['year'],
            $rSemantics['citedPaper']['authors'],
            $rSemantics['citedPaper']['citationStyles']['bibtex']
        );
    }

    private function hasUrlInTitle(mixed $title): bool
    {
        return str_contains((string) $title, 'https://') || str_contains((string) $title, 'http://');
    }

    /**
     * @throws \JsonException
     */
    private function insertRefS2fromDoi(string $doi, int $order, int $docId): void
    {
        $this->logger->info('S2 import: DOI found in cited paper', ['doi' => $doi]);
        $csl = $this->doiService->getCsl($doi);
        if ($csl === '') {
            $this->logger->info('S2 import: CSL not found for DOI', ['doi' => $doi]);
            return;
        }
        $newRef = ['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR), 'doi' => $doi];
        $this->insertRefInDb($newRef, $order, $docId);
    }

    /**
     * @param array<string, mixed> $externalIds
     * @throws \JsonException
     */
    private function insertRefFromArXivIdS2(array $externalIds, int $order, int $docId): void
    {
        $this->logger->info('S2 import: ArXiv ID found', ['arxiv' => $externalIds['ArXiv']]);
        $arxivId = $externalIds['ArXiv'];
        if (!str_contains((string) $arxivId, 'arxiv')) {
            $arxivId = 'arxiv.' . $arxivId;
        }
        $arxivId = self::PREFIX_ARXIV . $arxivId;
        $csl = $this->doiService->getCsl($arxivId);
        if ($csl === '') {
            $this->logger->info('S2 import: CSL not found for ArXiv ID', ['arxivId' => $arxivId]);
            return;
        }
        $newRef = ['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR), 'doi' => $arxivId];
        $this->insertRefInDb($newRef, $order, $docId);
    }

    /**
     * @throws \JsonException
     */
    private function insertCslFromBibtexS2(string $bibtex, int $order, int $docId): void
    {
        $this->logger->info('S2 import: no IDs, using BibTeX', ['bibtex' => $bibtex]);
        $bibInfo = $this->bibtexService::convertBibtexToArray($bibtex, false);
        $csl = $this->bibtexService::generateCSL($bibInfo[0]);
        $this->insertRefInDb(['csl' => $csl], $order, $docId);
    }

    /**
     * @param array<string, mixed> $citedPaper
     */
    private function insertFromUrlText(array $citedPaper, int $order, int $docId): void
    {
        $this->logger->info('S2 import: URL in title, creating minimal CSL', ['title' => $citedPaper['title'] ?? '']);
        $entry = [
            'title'  => $citedPaper['title'] ?? '',
            'type'   => $citedPaper['type'] ?? '',
            'author' => $citedPaper['authors'] ?? [],
            'year'   => $citedPaper['year'] ?? '',
        ];
        $csl = $this->bibtexService::generateCSL($entry);
        $this->insertRefInDb(['csl' => $csl], $order, $docId);
    }

    /**
     * @param array<string, mixed> $refRetrieved
     */
    private function insertRefInDb(array $refRetrieved, int $order, int $docId): void
    {
        $user = $this->entityManager->getRepository(UserInformations::class)->find(666);
        if ($user === null) {
            $user = new UserInformations();
            $user->setId(666);
            $user->setSurname('Episciences');
            $user->setName('System');
        }

        $reference = $this->solrReferenceEnricher->enrichReference($refRetrieved);

        $ref = new PaperReferences();
        $ref->setReference($reference);
        $ref->setSource(PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setReferenceOrder($order);

        if (!$this->references->getDocument($docId) instanceof Document) {
            $this->references->createDocumentId($docId);
        }
        $ref->setDocument($this->references->getDocument($docId));
        $ref->setAccepted(0);
        $ref->setUid($user);

        $this->entityManager->persist($ref);
        $this->entityManager->flush();
    }
}
