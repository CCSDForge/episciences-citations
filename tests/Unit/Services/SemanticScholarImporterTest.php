<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Exception\SemanticScholarImportException;
use App\Repository\PaperReferencesRepository;
use App\Repository\UserInformationsRepository;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use App\Services\Semanticsscholar;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SemanticScholarImporterTest extends TestCase
{
    private SemanticScholarImporter $importer;
    private MockObject $semanticsscholar;
    private MockObject $doiService;
    private Bibtex $bibtexService;
    private MockObject $references;
    private MockObject $entityManager;
    private MockObject $logger;
    private MockObject $solrReferenceEnricher;
    private MockObject $openAccessReferenceEnricher;
    private MockObject $paperReferencesRepository;
    private MockObject $userInformationsRepository;

    protected function setUp(): void
    {
        $this->semanticsscholar = $this->createMock(Semanticsscholar::class);
        $this->doiService = $this->createMock(Doi::class);
        // convertBibtexToArray()/generateCSL() are static, so PHPUnit cannot mock them:
        // a real instance is used, its own collaborators are irrelevant to those calls.
        $this->bibtexService = new Bibtex(
            $this->createStub(Doi::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(SolrReferenceEnricher::class)
        );
        $this->references = $this->createMock(References::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReference')->willReturnArgument(0);
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);
        $this->openAccessReferenceEnricher->method('enrichReference')->willReturnArgument(0);

        $this->paperReferencesRepository = $this->createMock(PaperReferencesRepository::class);
        $this->paperReferencesRepository->method('findBy')->willReturn([]);
        $this->userInformationsRepository = $this->createMock(UserInformationsRepository::class);
        $this->userInformationsRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): MockObject => match ($class) {
                PaperReferences::class => $this->paperReferencesRepository,
                UserInformations::class => $this->userInformationsRepository,
                default => throw new \RuntimeException('Unexpected repository requested: ' . $class),
            }
        );

        // By default, the document already exists so createDocumentId() is never expected to run.
        // Tests that need the "document doesn't exist yet" path override this explicitly.
        $this->references->method('getDocument')->willReturnCallback(
            fn (int $docId): Document => (new Document())->setId($docId)
        );

        $this->importer = new SemanticScholarImporter(
            $this->semanticsscholar,
            $this->doiService,
            $this->bibtexService,
            $this->references,
            $this->entityManager,
            $this->logger,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher,
        );
    }

    // ---------------------------------------------------------------------
    // importByPaperId() — top level guard clauses
    // ---------------------------------------------------------------------

    #[Test]
    public function testImportByPaperId_EmptyResponse_ThrowsException(): void
    {
        $this->semanticsscholar->method('getRef')->with('10.1234/missing')->willReturn('');
        $this->paperReferencesRepository->expects($this->never())->method('findBy');

        $this->expectException(SemanticScholarImportException::class);
        $this->expectExceptionMessage('DOI not found in Semantic Scholar');

        $this->importer->importByPaperId('10.1234/missing', 1, 0);
    }

    #[Test]
    public function testImportByPaperId_MissingDataKey_ThrowsException(): void
    {
        $this->semanticsscholar->method('getRef')->willReturn(json_encode(['message' => 'not found']));
        $this->paperReferencesRepository->expects($this->never())->method('findBy');

        $this->expectException(SemanticScholarImportException::class);
        $this->expectExceptionMessage('No references found for this paper ID');

        $this->importer->importByPaperId('10.1234/x', 1, 0);
    }

    #[Test]
    public function testImportByPaperId_EmptyDataArray_ThrowsException(): void
    {
        $this->semanticsscholar->method('getRef')->willReturn(json_encode(['data' => []]));
        $this->paperReferencesRepository->expects($this->never())->method('findBy');

        $this->expectException(SemanticScholarImportException::class);
        $this->expectExceptionMessage('No references found for this paper ID');

        $this->importer->importByPaperId('10.1234/x', 1, 0);
    }

    #[Test]
    public function testImportByPaperId_InvalidJsonResponse_ThrowsJsonException(): void
    {
        $this->semanticsscholar->method('getRef')->willReturn('{not-valid-json');

        $this->expectException(\JsonException::class);

        $this->importer->importByPaperId('10.1234/x', 1, 0);
    }

    // ---------------------------------------------------------------------
    // removeAllS2RefFromDb()
    // ---------------------------------------------------------------------

    #[Test]
    public function testRemoveAllS2RefFromDb_RemovesEveryExistingSemanticsScholarReference(): void
    {
        $docId = 42;
        $ref1 = new PaperReferences();
        $ref1->setId(101);
        $ref2 = new PaperReferences();
        $ref2->setId(102);

        $this->paperReferencesRepository = $this->createMock(PaperReferencesRepository::class);
        $this->paperReferencesRepository->expects($this->once())
            ->method('findBy')
            ->with(['document' => $docId, 'source' => PaperReferences::SOURCE_SEMANTICS_SCHOLAR])
            ->willReturn([$ref1, $ref2]);

        $removed = [];
        $this->entityManager->expects($this->exactly(2))
            ->method('remove')
            ->with($this->callback(function (PaperReferences $ref) use (&$removed): bool {
                $removed[] = $ref;
                return true;
            }));
        $this->entityManager->expects($this->once())->method('flush');

        $this->importer->removeAllS2RefFromDb($docId);

        $this->assertSame([$ref1, $ref2], $removed);
    }

    #[Test]
    public function testImportByPaperId_RemovesExistingReferencesBeforeImportingNewOnes(): void
    {
        $docId = 42;
        $existingRef = new PaperReferences();
        $existingRef->setId(999);

        // Fresh mock: setUp() already registered a default "findBy => []" matcher on the
        // shared instance, and PHPUnit resolves conflicting matchers in registration order
        // (first registered wins), so a second, more specific matcher would never be reached.
        $this->paperReferencesRepository = $this->createMock(PaperReferencesRepository::class);
        $this->paperReferencesRepository->expects($this->once())
            ->method('findBy')
            ->with(['document' => $docId, 'source' => PaperReferences::SOURCE_SEMANTICS_SCHOLAR])
            ->willReturn([$existingRef]);
        $this->entityManager->expects($this->once())->method('remove')->with($existingRef);

        // A single reference with no usable id at all (no DOI, no ArXiv, no BibTeX, no url in title):
        // it must be silently skipped, but removal must still have run beforehand.
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['title' => 'Untitled without any identifier']],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->entityManager->expects($this->never())->method('persist');

        $inserted = $this->importer->importByPaperId('10.1234/x', $docId, 0);

        $this->assertSame(0, $inserted);
    }

    // ---------------------------------------------------------------------
    // DOI path
    // ---------------------------------------------------------------------

    #[Test]
    public function testImportByPaperId_CitedPaperWithDoi_InsertsCslReferenceFromDoi(): void
    {
        $docId = 42;
        $doi = '10.1234/cited-paper';
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doi]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);

        $csl = ['title' => 'Cited Paper Title', 'type' => 'article-journal'];
        $this->doiService->expects($this->once())
            ->method('getCsl')
            ->with($doi)
            ->willReturn(json_encode($csl));

        $this->references->expects($this->never())->method('createDocumentId');

        $persistedRef = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (PaperReferences $ref) use (&$persistedRef): bool {
                $persistedRef = $ref;
                return true;
            }));
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $inserted = $this->importer->importByPaperId($doi, $docId, 5);

        $this->assertSame(1, $inserted);
        $this->assertInstanceOf(PaperReferences::class, $persistedRef);
        $this->assertSame(PaperReferences::SOURCE_SEMANTICS_SCHOLAR, $persistedRef->getSource());
        $this->assertSame(5, $persistedRef->getReferenceOrder());
        $this->assertSame(1, $persistedRef->getAccepted());
        $this->assertSame($csl, $persistedRef->getReference()['csl']);
        $this->assertSame($doi, $persistedRef->getReference()['doi']);
        $this->assertSame($docId, $persistedRef->getDocument()->getId());
        $this->assertNotNull($persistedRef->getUid());
        $this->assertSame(666, $persistedRef->getUid()->getId());
        $this->assertSame('Episciences', $persistedRef->getUid()->getSurname());
        $this->assertSame('System', $persistedRef->getUid()->getName());
    }

    #[Test]
    public function testImportByPaperId_DoiPath_CslNotFound_SkipsInsertionAndReturnsZero(): void
    {
        $doi = '10.1234/unresolvable';
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doi]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->method('getCsl')->with($doi)->willReturn('');

        $this->entityManager->expects($this->never())->method('persist');

        $inserted = $this->importer->importByPaperId($doi, 1, 0);

        $this->assertSame(0, $inserted);
    }

    #[Test]
    public function testImportByPaperId_ExistingUser_IsReusedInsteadOfCreatingANewOne(): void
    {
        $existingUser = new UserInformations();
        $existingUser->setId(666);
        $existingUser->setName('Existing');
        $existingUser->setSurname('User');

        // Fresh mock — see comment in testImportByPaperId_RemovesExistingReferencesBeforeImportingNewOnes().
        $this->userInformationsRepository = $this->createMock(UserInformationsRepository::class);
        $this->userInformationsRepository->method('find')->with(666)->willReturn($existingUser);

        $doi = '10.1234/cited-paper';
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doi]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->method('getCsl')->willReturn(json_encode(['title' => 'Title']));

        $persistedRef = null;
        $this->entityManager->method('persist')->willReturnCallback(function (PaperReferences $ref) use (&$persistedRef): void {
            $persistedRef = $ref;
        });

        $this->importer->importByPaperId($doi, 1, 0);

        $this->assertSame($existingUser, $persistedRef->getUid());
    }

    #[Test]
    public function testImportByPaperId_DocumentDoesNotExistYet_CreatesItBeforeAssigningTheReference(): void
    {
        $docId = 777;
        $createdDocument = (new Document())->setId($docId);

        $this->references = $this->createMock(References::class);
        $this->references->method('getDocument')->willReturn(null);
        $this->references->expects($this->once())
            ->method('createDocumentId')
            ->with($docId)
            ->willReturn($createdDocument);

        $this->importer = new SemanticScholarImporter(
            $this->semanticsscholar,
            $this->doiService,
            $this->bibtexService,
            $this->references,
            $this->entityManager,
            $this->logger,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher,
        );

        $doi = '10.1234/cited-paper';
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doi]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->method('getCsl')->willReturn(json_encode(['title' => 'Title']));

        $inserted = $this->importer->importByPaperId($doi, $docId, 0);

        $this->assertSame(1, $inserted);
    }

    /**
     * Regression test for a data-mapping bug: hasArxiv()/hasBibTeX() used to check
     * isset($DOI) directly, while hasDoi() (correctly) also requires it to be non-empty.
     * A citedPaper with externalIds.DOI === '' alongside a valid ArXiv id used to fall
     * through every branch of processS2Ref() and be silently dropped instead of being
     * imported via its ArXiv id.
     */
    #[Test]
    public function testImportByPaperId_EmptyDoiWithArxivId_FallsBackToArxivInsteadOfBeingDropped(): void
    {
        $docId = 42;
        $raw = json_encode([
            'data' => [
                [
                    'citedPaper' => [
                        'externalIds' => ['DOI' => '', 'ArXiv' => '1234.5678'],
                    ],
                ],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);

        $expectedArxivDoi = '10.48550/arxiv.1234.5678';
        $this->doiService->expects($this->once())
            ->method('getCsl')
            ->with($expectedArxivDoi)
            ->willReturn(json_encode(['title' => 'ArXiv Fallback Title']));

        $inserted = $this->importer->importByPaperId('10.1234/x', $docId, 0);

        $this->assertSame(1, $inserted);
    }

    // ---------------------------------------------------------------------
    // ArXiv path
    // ---------------------------------------------------------------------

    #[Test]
    public function testImportByPaperId_ArxivPath_PrefixesIdWhenArxivSubstringMissing(): void
    {
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['ArXiv' => 'hep-th/9901001']]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);

        $this->doiService->expects($this->once())
            ->method('getCsl')
            ->with('10.48550/arxiv.hep-th/9901001')
            ->willReturn(json_encode(['title' => 'ArXiv Title']));

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 0);

        $this->assertSame(1, $inserted);
    }

    #[Test]
    public function testImportByPaperId_ArxivPath_DoesNotDoublePrefixWhenArxivSubstringAlreadyPresent(): void
    {
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['ArXiv' => 'arxiv:1234.5678']]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);

        $this->doiService->expects($this->once())
            ->method('getCsl')
            ->with('10.48550/arxiv:1234.5678')
            ->willReturn(json_encode(['title' => 'ArXiv Title']));

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 0);

        $this->assertSame(1, $inserted);
    }

    #[Test]
    public function testImportByPaperId_ArxivPath_CslNotFound_SkipsInsertion(): void
    {
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['ArXiv' => '1234.5678']]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->method('getCsl')->willReturn('');

        $this->entityManager->expects($this->never())->method('persist');

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 0);

        $this->assertSame(0, $inserted);
    }

    // ---------------------------------------------------------------------
    // BibTeX path
    // ---------------------------------------------------------------------

    #[Test]
    public function testImportByPaperId_BibtexPath_WithMandatoryFields_GeneratesCslFromRealBibtex(): void
    {
        $bibtex = "@article{key2020,\n"
            . "  author = {Doe, John},\n"
            . "  title = {Some Cited Title},\n"
            . "  journal = {Some Journal},\n"
            . "  year = {2020}\n"
            . '}';

        $raw = json_encode([
            'data' => [
                [
                    'citedPaper' => [
                        'title' => 'Some Cited Title',
                        'year' => 2020,
                        'authors' => [['name' => 'John Doe']],
                        'citationStyles' => ['bibtex' => $bibtex],
                    ],
                ],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->expects($this->never())->method('getCsl');

        $persistedRef = null;
        $this->entityManager->method('persist')->willReturnCallback(function (PaperReferences $ref) use (&$persistedRef): void {
            $persistedRef = $ref;
        });

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 3);

        $this->assertSame(1, $inserted);
        $this->assertArrayNotHasKey('doi', $persistedRef->getReference());
        $csl = $persistedRef->getReference()['csl'];
        $this->assertSame('article', $csl['type']);
        $this->assertSame('Some Cited Title', $csl['title']);
        $this->assertSame('Some Journal', $csl['container-title']);
        $this->assertSame([['family' => 'Doe', 'given' => 'John']], $csl['author']);
        $this->assertSame(3, $persistedRef->getReferenceOrder());
    }

    #[Test]
    public function testImportByPaperId_BibtexPath_MissingMandatoryFieldsButUrlInTitle_InsertsMinimalCsl(): void
    {
        $raw = json_encode([
            'data' => [
                [
                    'citedPaper' => [
                        // No "year"/"citationStyles" -> hasMandatoryBibtexInfo() is false.
                        'title' => 'See https://arxiv.org/abs/1234.5678 for the full text',
                        'type' => 'article',
                    ],
                ],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->expects($this->never())->method('getCsl');

        $persistedRef = null;
        $this->entityManager->method('persist')->willReturnCallback(function (PaperReferences $ref) use (&$persistedRef): void {
            $persistedRef = $ref;
        });

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 0);

        $this->assertSame(1, $inserted);
        $csl = $persistedRef->getReference()['csl'];
        $this->assertSame('See https://arxiv.org/abs/1234.5678 for the full text', $csl['title']);
        $this->assertSame('article', $csl['type']);
    }

    #[Test]
    public function testImportByPaperId_BibtexPath_MissingMandatoryFieldsAndNoUrl_SkipsReference(): void
    {
        $raw = json_encode([
            'data' => [
                [
                    'citedPaper' => [
                        'title' => 'A plain title without any identifier or url',
                    ],
                ],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->expects($this->never())->method('getCsl');
        $this->entityManager->expects($this->never())->method('persist');

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 0);

        $this->assertSame(0, $inserted);
    }

    // ---------------------------------------------------------------------
    // Multiple entries / ordering / enrichment
    // ---------------------------------------------------------------------

    #[Test]
    public function testImportByPaperId_MultipleReferences_OnlyCountsSuccessfulInsertsAndKeepsOrderSequential(): void
    {
        $doiSuccess = '10.1/success';
        $doiSkipped = '10.2/skipped';
        $arxivId = '9999.0001';
        $expectedArxivDoi = '10.48550/arxiv.' . $arxivId;

        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doiSuccess]]],
                ['citedPaper' => ['externalIds' => ['DOI' => $doiSkipped]]],
                ['citedPaper' => ['externalIds' => ['ArXiv' => $arxivId]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);

        $this->doiService->method('getCsl')->willReturnCallback(
            fn (string $doi): string => match ($doi) {
                $doiSuccess => json_encode(['title' => 'First']),
                $doiSkipped => '',
                $expectedArxivDoi => json_encode(['title' => 'Third']),
                default => '',
            }
        );

        $persistedRefs = [];
        $this->entityManager->method('persist')->willReturnCallback(function (PaperReferences $ref) use (&$persistedRefs): void {
            $persistedRefs[] = $ref;
        });

        $inserted = $this->importer->importByPaperId('10.1234/x', 1, 5);

        $this->assertSame(2, $inserted);
        $this->assertCount(2, $persistedRefs);
        $this->assertSame(5, $persistedRefs[0]->getReferenceOrder());
        $this->assertSame('First', $persistedRefs[0]->getReference()['csl']['title']);
        $this->assertSame(6, $persistedRefs[1]->getReferenceOrder());
        $this->assertSame('Third', $persistedRefs[1]->getReference()['csl']['title']);
    }

    #[Test]
    public function testImportByPaperId_AppliesSolrAndOpenAccessEnrichmentToInsertedReference(): void
    {
        $doi = '10.1234/cited-paper';
        $raw = json_encode([
            'data' => [
                ['citedPaper' => ['externalIds' => ['DOI' => $doi]]],
            ],
        ]);
        $this->semanticsscholar->method('getRef')->willReturn($raw);
        $this->doiService->method('getCsl')->willReturn(json_encode(['title' => 'Title']));

        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReference')->willReturnCallback(
            static function (array $reference): array {
                $reference['detectors'] = ['clayFeet'];
                return $reference;
            }
        );
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);
        $this->openAccessReferenceEnricher->method('enrichReference')->willReturnCallback(
            static function (array $reference): array {
                $reference['open-access'] = ['url' => 'https://oa.example.org/paper'];
                return $reference;
            }
        );

        $this->importer = new SemanticScholarImporter(
            $this->semanticsscholar,
            $this->doiService,
            $this->bibtexService,
            $this->references,
            $this->entityManager,
            $this->logger,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher,
        );

        $persistedRef = null;
        $this->entityManager->method('persist')->willReturnCallback(function (PaperReferences $ref) use (&$persistedRef): void {
            $persistedRef = $ref;
        });

        $this->importer->importByPaperId($doi, 1, 0);

        $this->assertSame(['clayFeet'], $persistedRef->getReference()['detectors']);
        $this->assertSame(['url' => 'https://oa.example.org/paper'], $persistedRef->getReference()['open-access']);
    }
}
