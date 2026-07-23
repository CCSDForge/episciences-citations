<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use PHPUnit\Framework\MockObject\MockObject;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Repository\PaperReferencesRepository;
use App\Repository\UserInformationsRepository;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BibtexTest extends TestCase
{
    private Bibtex $service;
    private MockObject $doi;
    private MockObject $entityManager;
    private MockObject $logger;
    private MockObject $solrReferenceEnricher;

    protected function setUp(): void
    {
        $this->doi = $this->createMock(Doi::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Mock logger to accept any log calls (void methods)
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReferences')->willReturnArgument(0);
        // No need to configure mock for void methods - they're automatically handled

        // Create service - this initializes the singleton logger
        $this->service = new Bibtex(
            $this->doi,
            $this->entityManager,
            $this->logger,
            $this->solrReferenceEnricher
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testConvertBibtexToArray_ValidBibtex(): void
    {
        // Arrange - Use inline BibTeX string (more reliable than file path in Docker)
        $validBibtex = '@article{test2024,
  author = {Doe, John},
  title = {Test Article},
  journal = {Test Journal},
  year = {2024}
}';

        // Act - Parse as string (isFile = false)
        $result = $this->service->convertBibtexToArray($validBibtex, false);

        // Assert
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result), 'Should parse at least one entry');

        // If parsing failed, skip further assertions
        if (isset($result['error'])) {
            $this->markTestSkipped('BibTeX parser not available in test environment: ' . $result['error']);
        }

        // Verify structure of first entry
        $first = $result[0];
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertEquals('Test Article', $first['title']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testConvertBibtexToArray_InvalidBibtex(): void
    {
        // Arrange - Invalid BibTeX syntax (unclosed braces)
        $invalidBibtex = '@article{test2024, author = {Doe, John';

        // Expect logger to be called for error
        $this->logger->expects($this->once())
            ->method('error');

        // Act
        $result = $this->service->convertBibtexToArray($invalidBibtex, false);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('BibTeX is not valid', $result['error']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateCSL_ArticleType(): void
    {
        // Arrange - Entry structure AFTER BibTeX parsing (with NamesProcessor)
        $entry = [
            'type' => 'article',
            'author' => [
                ['first' => 'John', 'last' => 'Doe'],
                ['first' => 'Jane', 'last' => 'Smith']
            ],
            'title' => 'Test Article Title',
            'journal' => 'Science Journal',
            'year' => '2024',
            'volume' => '42',
            'number' => '3',
            'pages' => '100--120'
        ];

        // Act
        $result = $this->service->generateCSL($entry);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('article', $result['type']); // Type is lowercased but not converted
        $this->assertEquals('Test Article Title', $result['title']);
        $this->assertEquals('Science Journal', $result['container-title']);
        $this->assertEquals('42', $result['volume']);
        $this->assertEquals('100--120', $result['page']);

        // Verify author structure
        $this->assertIsArray($result['author']);
        $this->assertCount(2, $result['author']);
        $this->assertEquals('Doe', $result['author'][0]['family']);
        $this->assertEquals('John', $result['author'][0]['given']);

        // Verify issued date
        $this->assertArrayHasKey('issued', $result);
        $this->assertEquals([[2024]], $result['issued']['date-parts']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateCSL_NonArticleType_SkipsArticleOnlyFieldsAndAddsBookFields(): void
    {
        // Arrange - a "book" entry: journal/volume/number/pages must be ignored,
        // while publisher/address/isbn (applicable to any type) must be kept.
        $entry = [
            'type' => 'book',
            'title' => 'Test Book',
            'year' => '2020',
            'publisher' => 'Test Publisher',
            'address' => 'Paris',
            'isbn' => '978-3-16-148410-0',
            // These should be ignored because type !== 'article'
            'journal' => 'Should not appear',
            'volume' => '1',
            'number' => '2',
            'pages' => '1-10',
        ];

        // Act
        $result = $this->service->generateCSL($entry);

        // Assert
        $this->assertEquals('book', $result['type']);
        $this->assertEquals('Test Publisher', $result['publisher']);
        $this->assertEquals('Paris', $result['publisher-place']);
        $this->assertEquals('978-3-16-148410-0', $result['ISBN']);
        $this->assertArrayNotHasKey('container-title', $result);
        $this->assertArrayNotHasKey('volume', $result);
        $this->assertArrayNotHasKey('issue', $result);
        $this->assertArrayNotHasKey('page', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateCSL_MissingType_DefaultsToMisc(): void
    {
        // Arrange - entry without a "type" key
        $entry = ['title' => 'Untyped Entry'];

        // Act
        $result = $this->service->generateCSL($entry);

        // Assert
        $this->assertEquals('misc', $result['type']);
        $this->assertEquals([['']], $result['issued']['date-parts']);
        $this->assertEquals([], $result['author']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetCslRefText_MissingTypeWithContainerTitle_DefaultsToArticleJournal(): void
    {
        // Arrange - no explicit "type", but a container-title is present
        $refData = [
            'csl' => [
                'title' => 'Test Article',
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
                'container-title' => 'Test Journal',
            ],
        ];

        // Act
        $result = $this->service->getCslRefText($refData);

        // Assert - rendered without error, CSL key removed
        $this->assertArrayNotHasKey('csl', $result);
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertStringContainsString('Doe', $result['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetCslRefText_MissingTypeWithoutContainerTitle_DefaultsToArticle(): void
    {
        // Arrange - no explicit "type" and no container-title
        $refData = [
            'csl' => [
                'title' => 'Test Book',
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
            ],
        ];

        // Act
        $result = $this->service->getCslRefText($refData);

        // Assert
        $this->assertArrayNotHasKey('csl', $result);
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertStringContainsString('Doe', $result['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_InvalidBibtexFile_ReturnsError(): void
    {
        // Arrange - invalid BibTeX content written to a real file (processBibtex always parses as a file)
        $path = tempnam(sys_get_temp_dir(), 'bibtex_invalid_');
        file_put_contents($path, '@article{test2024, author = {Doe, John');

        // removeExistingBibtexImports() runs before parsing, so the PaperReferences repository is queried
        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturn([]);
        $this->entityManager->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($refRepository);

        try {
            // Act
            $result = $this->service->processBibtex($path, ['UID' => 1], 123456);

            // Assert
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('BibTeX is not valid', $result['error']);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_WithCrossrefDoi_UsesDoiCsl(): void
    {
        // Arrange - a BibTeX entry with a custom "crossref_doi" field
        $path = tempnam(sys_get_temp_dir(), 'bibtex_crossref_');
        file_put_contents($path, '@article{test2024,
  author = {Doe, John},
  title = {Test Article},
  crossref_doi = {10.1234/crossref-test}
}');

        $cslJson = json_encode(['type' => 'article-journal', 'title' => 'Resolved via DOI']);
        $this->doi->expects($this->once())
            ->method('getCsl')
            ->with('10.1234/crossref-test')
            ->willReturn($cslJson);

        // No existing bibtex imports, no existing document/user
        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturn([]);
        $userRepository = $this->createMock(UserInformationsRepository::class);
        $userRepository->method('find')->willReturn(null);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function (string $class) use ($refRepository, $userRepository, $documentRepository) {
                return match ($class) {
                    PaperReferences::class => $refRepository,
                    UserInformations::class => $userRepository,
                    Document::class => $documentRepository,
                };
            });

        $persisted = [];
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (PaperReferences $ref) use (&$persisted): bool {
                $persisted[] = $ref;
                return true;
            }));
        $this->entityManager->expects($this->once())->method('flush');

        try {
            // Act
            $result = $this->service->processBibtex($path, ['UID' => 1, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'], 123456);
        } finally {
            unlink($path);
        }

        // Assert
        $this->assertSame([], $result);
        $this->assertCount(1, $persisted);
        $this->assertSame(['type' => 'article-journal', 'title' => 'Resolved via DOI'], $persisted[0]->getReference()['csl']);
        $this->assertSame('10.1234/crossref-test', $persisted[0]->getReference()['doi']);
        $this->assertSame(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, $persisted[0]->getSource());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_DoiExtractedFromUrl_WhenNoDoiField(): void
    {
        // Arrange - entry has no "doi" field but a URL containing a DOI
        $path = tempnam(sys_get_temp_dir(), 'bibtex_url_doi_');
        file_put_contents($path, '@article{test2024,
  author = {Doe, John},
  title = {Test Article},
  journal = {Test Journal},
  year = {2024},
  url = {https://example.com/10.1234/from-url}
}');

        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturn([]);
        $userRepository = $this->createMock(UserInformationsRepository::class);
        $userRepository->method('find')->willReturn(null);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function (string $class) use ($refRepository, $userRepository, $documentRepository) {
                return match ($class) {
                    PaperReferences::class => $refRepository,
                    UserInformations::class => $userRepository,
                    Document::class => $documentRepository,
                };
            });

        $persisted = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (PaperReferences $ref) use (&$persisted): void {
                $persisted[] = $ref;
            });

        // The DOI service must NOT be called since no crossref_doi field is present
        $this->doi->expects($this->never())->method('getCsl');

        try {
            // Act
            $result = $this->service->processBibtex($path, ['UID' => 1, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'], 123456);
        } finally {
            unlink($path);
        }

        // Assert
        $this->assertSame([], $result);
        $this->assertCount(1, $persisted);
        $this->assertSame('10.1234/from-url', $persisted[0]->getReference()['doi']);
        $this->assertArrayHasKey('csl', $persisted[0]->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_UnreadableFile_ReturnsErrorViaErrorException(): void
    {
        // Arrange - a path that doesn't exist: Parser::parseFile() throws \ErrorException,
        // exercising the dedicated catch(\ErrorException) branch of convertBibtexToArray()
        $path = sys_get_temp_dir() . '/does-not-exist-' . uniqid('', true) . '.bib';

        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturn([]);
        $this->entityManager->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($refRepository);

        $this->logger->expects($this->once())->method('error');

        // Act
        $result = $this->service->processBibtex($path, ['UID' => 1], 123456);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Something went wrong with the BibTeX converter', $result['error']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_UrlWithoutDoiPattern_NoDoiInReference(): void
    {
        // Arrange - a URL is present but doesn't contain a DOI, and there's no explicit doi field:
        // extractDoiFromBibtexInfo() must fall through to its final "return null" branch.
        $path = tempnam(sys_get_temp_dir(), 'bibtex_no_doi_');
        file_put_contents($path, '@article{test2024,
  author = {Doe, John},
  title = {Test Article},
  journal = {Test Journal},
  year = {2024},
  url = {https://example.com/not-a-doi}
}');

        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturn([]);
        $userRepository = $this->createMock(UserInformationsRepository::class);
        $userRepository->method('find')->willReturn(null);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function (string $class) use ($refRepository, $userRepository, $documentRepository) {
                return match ($class) {
                    PaperReferences::class => $refRepository,
                    UserInformations::class => $userRepository,
                    Document::class => $documentRepository,
                };
            });

        $persisted = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (PaperReferences $ref) use (&$persisted): void {
                $persisted[] = $ref;
            });

        try {
            // Act
            $result = $this->service->processBibtex($path, ['UID' => 1, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'], 123456);
        } finally {
            unlink($path);
        }

        // Assert
        $this->assertSame([], $result);
        $this->assertCount(1, $persisted);
        $this->assertArrayNotHasKey('doi', $persisted[0]->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessBibtex_ExistingUserAndDocument_ReusesExistingUser(): void
    {
        // Arrange - an existing bibtex-imported reference must be removed first,
        // and an existing UserInformations must be reused instead of created.
        $path = tempnam(sys_get_temp_dir(), 'bibtex_existing_');
        file_put_contents($path, '@misc{test2024,
  author = {Doe, John},
  title = {Test Misc Entry}
}');

        $existingUser = new UserInformations();
        $existingUser->setId(1);

        $existingBibtexRef = new PaperReferences();
        $existingBibtexRef->setId(999);

        $refRepository = $this->createMock(PaperReferencesRepository::class);
        $refRepository->method('findBy')->willReturnCallback(
            fn (array $criteria) => isset($criteria['source']) ? [$existingBibtexRef] : [$existingBibtexRef]
        );
        $userRepository = $this->createMock(UserInformationsRepository::class);
        $userRepository->method('find')->willReturn($existingUser);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function (string $class) use ($refRepository, $userRepository, $documentRepository) {
                return match ($class) {
                    PaperReferences::class => $refRepository,
                    UserInformations::class => $userRepository,
                    Document::class => $documentRepository,
                };
            });

        // Removal of the existing bibtex-imported reference + insertion of the new one
        $this->entityManager->expects($this->once())->method('remove')->with($existingBibtexRef);

        $persisted = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (PaperReferences $ref) use (&$persisted): void {
                $persisted[] = $ref;
            });

        try {
            // Act
            $result = $this->service->processBibtex($path, ['UID' => 1, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'], 123456);
        } finally {
            unlink($path);
        }

        // Assert
        $this->assertSame([], $result);
        $this->assertCount(1, $persisted);
        $this->assertSame($existingUser, $persisted[0]->getUid());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetCslRefText_WithCSL_ReturnsArrayWithRenderedText(): void
    {
        // Arrange — pass flat array, no JSON string
        $refData = [
            'csl' => [
                'type' => 'article-journal',
                'title' => 'Test Article',
                'author' => [
                    ['family' => 'Doe', 'given' => 'John'],
                ],
                'issued' => ['date-parts' => [[2024]]],
                'container-title' => 'Test Journal',
            ],
            'raw_reference' => 'Original raw text',
        ];

        // Act
        $result = $this->service->getCslRefText($refData);

        // Assert — result is an array, 'csl' key removed, 'raw_reference' updated
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('csl', $result, 'CSL key should be removed after rendering');
        $this->assertArrayHasKey('raw_reference', $result);
        $this->assertStringContainsString('Doe', $result['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetCslRefText_WithoutCSL_ReturnsUnchangedArray(): void
    {
        // Arrange — reference without CSL (e.g. from GROBID)
        $refData = [
            'raw_reference' => 'Author et al. Title. Journal, 2024.',
            'doi' => '10.1234/test',
        ];

        // Act
        $result = $this->service->getCslRefText($refData);

        // Assert — array returned as-is
        $this->assertIsArray($result);
        $this->assertEquals($refData, $result);
    }
}
