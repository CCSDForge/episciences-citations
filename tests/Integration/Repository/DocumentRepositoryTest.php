<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for DocumentRepository backed by an in-memory SQLite database.
 */
final class DocumentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentRepository $repository;

    protected function setUp(): void
    {
        // The container is compiled with DATABASE_URL resolved from the real
        // OS environment (docker-compose exports a MySQL DSN even for
        // APP_ENV=test), which takes precedence over .env.test's sqlite
        // value. Force a fresh in-memory SQLite database for this process
        // before booting the kernel so these tests stay fast and isolated.
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        /** @var DocumentRepository $repository */
        $repository = $this->entityManager->getRepository(Document::class);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->entityManager, $this->repository);
    }

    /**
     * Seeds the same data set as `tests/Fixtures/DocumentFixtures.php` +
     * `PaperReferencesFixtures.php` (3 documents, 10 references, referenceOrder
     * 0..4 per document).
     *
     * Note: the shared fixture classes under `tests/Fixtures/` cannot actually
     * be loaded against the installed `doctrine/data-fixtures:2.2.1` — they
     * call `AbstractFixture::getReference($name)` with a single argument, but
     * that version requires `getReference(string $name, string $class)` (a
     * `TypeError`/`ArgumentCountError` results). This pre-existing
     * incompatibility is out of this test's scope to fix (see final report),
     * so the equivalent rows are built directly here instead of duplicating
     * broken fixture-loading logic.
     */
    private function seedDocumentsAndReferences(): void
    {
        $doc1 = new Document();
        $doc1->setId(123456);
        $doc2 = new Document();
        $doc2->setId(789012);
        $doc3 = new Document();
        $doc3->setId(333333);

        foreach ([$doc1, $doc2, $doc3] as $document) {
            $this->entityManager->persist($document);
        }

        $doc1References = [
            [PaperReferences::SOURCE_METADATA_GROBID, 1],
            [PaperReferences::SOURCE_METADATA_EPI_USER, 1],
            [PaperReferences::SOURCE_METADATA_EPI_USER, 1],
            [PaperReferences::SOURCE_METADATA_GROBID, 0],
            [PaperReferences::SOURCE_METADATA_GROBID, 0],
        ];
        foreach ($doc1References as $order => [$source, $accepted]) {
            $this->entityManager->persist($this->buildReference($doc1, $source, $accepted, $order));
        }

        $doc2References = [
            [PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, 1],
            [PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, 0],
            [PaperReferences::SOURCE_SEMANTICS_SCHOLAR, 1],
            [PaperReferences::SOURCE_METADATA_GROBID, 1],
            [PaperReferences::SOURCE_METADATA_GROBID, 0],
        ];
        foreach ($doc2References as $order => [$source, $accepted]) {
            $this->entityManager->persist($this->buildReference($doc2, $source, $accepted, $order));
        }

        $this->entityManager->flush();

        // Detach everything so a subsequent find() re-hydrates the Document
        // (and its OneToMany PaperReferences collection) from the database
        // instead of returning the identity-mapped instances built above,
        // whose in-memory collection was never populated from a query.
        $this->entityManager->clear();
    }

    private function buildReference(Document $document, string $source, int $accepted, int $order): PaperReferences
    {
        $reference = new PaperReferences();
        $reference->setReference(['raw_reference' => "Reference {$order}"]);
        $reference->setSource($source);
        $reference->setAccepted($accepted);
        $reference->setReferenceOrder($order);
        $reference->setDocument($document);
        $reference->setUpdatedAt(new \DateTimeImmutable());

        return $reference;
    }

    #[Test]
    public function testFindReturnsPersistedDocument(): void
    {
        $this->seedDocumentsAndReferences();

        $document = $this->repository->find(123456);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame(123456, $document->getId());
    }

    #[Test]
    public function testFindReturnsNullForNonExistentDocument(): void
    {
        $this->seedDocumentsAndReferences();

        $this->assertNull($this->repository->find(999999));
    }

    #[Test]
    public function testFindAllReturnsEveryPersistedDocument(): void
    {
        $this->seedDocumentsAndReferences();

        $documents = $this->repository->findAll();
        $ids = array_map(static fn (Document $document): int => $document->getId(), $documents);
        sort($ids);

        $this->assertCount(3, $documents);
        $this->assertSame([123456, 333333, 789012], $ids);
    }

    #[Test]
    public function testFindAllReturnsEmptyArrayWhenNoDocumentsExist(): void
    {
        $this->assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function testSaveWithFlushTruePersistsDocumentImmediately(): void
    {
        $document = new Document();
        $document->setId(555);

        $this->repository->save($document, true);
        $this->entityManager->clear();

        $persisted = $this->repository->find(555);
        $this->assertInstanceOf(Document::class, $persisted);
        $this->assertSame(555, $persisted->getId());
    }

    #[Test]
    public function testSaveWithFlushFalseDoesNotPersistUntilManualFlush(): void
    {
        $document = new Document();
        $document->setId(556);

        $this->repository->save($document, false);

        // Not flushed yet: a direct SQL read must not see the row.
        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM document WHERE id = ?', [556])
            ->fetchOne();
        $this->assertSame(0, $count, 'save() with flush=false must not write to the database yet');

        $this->entityManager->flush();

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM document WHERE id = ?', [556])
            ->fetchOne();
        $this->assertSame(1, $count, 'a subsequent manual flush() must persist the previously scheduled entity');
    }

    #[Test]
    public function testRemoveWithFlushTrueDeletesDocumentImmediately(): void
    {
        $document = new Document();
        $document->setId(557);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->repository->remove($document, true);
        $this->entityManager->clear();

        $this->assertNull($this->repository->find(557));
    }

    #[Test]
    public function testRemoveWithFlushFalseDoesNotDeleteUntilManualFlush(): void
    {
        $document = new Document();
        $document->setId(558);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->repository->remove($document, false);

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM document WHERE id = ?', [558])
            ->fetchOne();
        $this->assertSame(1, $count, 'remove() with flush=false must not delete from the database yet');

        $this->entityManager->flush();

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM document WHERE id = ?', [558])
            ->fetchOne();
        $this->assertSame(0, $count);
    }

    #[Test]
    public function testPaperReferencesAreEagerlyOrderedByReferenceOrderAscending(): void
    {
        $this->seedDocumentsAndReferences();

        $document = $this->repository->find(123456);
        $this->assertNotNull($document);

        $orders = array_map(
            static fn ($reference) => $reference->getReferenceOrder(),
            $document->getPaperReferences()->toArray()
        );

        // Document 1 has 5 references inserted with referenceOrder 0..4; the
        // entity's #[ORM\OrderBy(["referenceOrder" => "ASC"])] mapping must be
        // honoured when the collection is loaded through the repository.
        $this->assertSame([0, 1, 2, 3, 4], $orders);
    }

    #[Test]
    public function testDocumentWithoutReferencesHasEmptyPaperReferencesCollection(): void
    {
        $this->seedDocumentsAndReferences();

        $document = $this->repository->find(333333);
        $this->assertNotNull($document);
        $this->assertCount(0, $document->getPaperReferences());
    }
}
