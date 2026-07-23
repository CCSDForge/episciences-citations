<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for PaperReferencesRepository backed by an in-memory SQLite database.
 */
final class PaperReferencesRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PaperReferencesRepository $repository;

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

        /** @var PaperReferencesRepository $repository */
        $repository = $this->entityManager->getRepository(PaperReferences::class);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->entityManager, $this->repository);
    }

    /**
     * Seeds the same data set as `tests/Fixtures/DocumentFixtures.php` +
     * `UserFixtures.php` + `PaperReferencesFixtures.php` (2 users, 3
     * documents, 10 references).
     *
     * Note: the shared fixture classes under `tests/Fixtures/` cannot actually
     * be loaded against the installed `doctrine/data-fixtures:2.2.1` — they
     * call `AbstractFixture::getReference($name)` with a single argument, but
     * that version requires `getReference(string $name, string $class)` (an
     * `ArgumentCountError` results). This pre-existing incompatibility is out
     * of this test's scope to fix (see final report), so the equivalent rows
     * are built directly here instead of duplicating broken fixture-loading
     * logic.
     */
    private function seedDocumentsUsersAndReferences(): void
    {
        $user1 = new UserInformations();
        $user1->setId(1001);
        $user1->setName('Doe');
        $user1->setSurname('John');

        $user2 = new UserInformations();
        $user2->setId(2002);
        $user2->setName('Smith');
        $user2->setSurname('Jane');

        $doc1 = new Document();
        $doc1->setId(123456);
        $doc2 = new Document();
        $doc2->setId(789012);
        $doc3 = new Document();
        $doc3->setId(333333);

        foreach ([$user1, $user2, $doc1, $doc2, $doc3] as $entity) {
            $this->entityManager->persist($entity);
        }

        $doc1References = [
            [PaperReferences::SOURCE_METADATA_GROBID, 1, $user1],
            [PaperReferences::SOURCE_METADATA_EPI_USER, 1, $user2],
            [PaperReferences::SOURCE_METADATA_EPI_USER, 1, $user1],
            [PaperReferences::SOURCE_METADATA_GROBID, 0, null],
            [PaperReferences::SOURCE_METADATA_GROBID, 0, null],
        ];
        foreach ($doc1References as $order => [$source, $accepted, $uid]) {
            $this->entityManager->persist($this->buildReference($doc1, $source, $accepted, $order, $uid));
        }

        $doc2References = [
            [PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, 1, $user1],
            [PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, 0, null],
            [PaperReferences::SOURCE_SEMANTICS_SCHOLAR, 1, $user2],
            [PaperReferences::SOURCE_METADATA_GROBID, 1, null],
            [PaperReferences::SOURCE_METADATA_GROBID, 0, null],
        ];
        foreach ($doc2References as $order => [$source, $accepted, $uid]) {
            $this->entityManager->persist($this->buildReference($doc2, $source, $accepted, $order, $uid));
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function buildReference(
        Document $document,
        string $source,
        int $accepted,
        int $order,
        ?UserInformations $uid = null
    ): PaperReferences {
        $reference = new PaperReferences();
        $reference->setReference(['raw_reference' => "Reference {$order}"]);
        $reference->setSource($source);
        $reference->setAccepted($accepted);
        $reference->setReferenceOrder($order);
        $reference->setDocument($document);
        $reference->setUid($uid);
        $reference->setUpdatedAt(new \DateTimeImmutable());

        return $reference;
    }

    #[Test]
    public function testFindByDocumentOrderedByReferenceOrderReturnsAllFiveInOrder(): void
    {
        $this->seedDocumentsUsersAndReferences();

        $document = $this->entityManager->getRepository(Document::class)->find(123456);
        $references = $this->repository->findBy(['document' => $document], ['referenceOrder' => 'ASC']);

        $this->assertCount(5, $references);
        $this->assertSame(
            [0, 1, 2, 3, 4],
            array_map(static fn (PaperReferences $reference) => $reference->getReferenceOrder(), $references)
        );
        $this->assertSame([1, 1, 1, 0, 0], array_map(
            static fn (PaperReferences $reference) => $reference->getAccepted(),
            $references
        ));
    }

    #[Test]
    public function testFindByAcceptedDiscriminatesAcceptedFromPendingReferences(): void
    {
        $this->seedDocumentsUsersAndReferences();

        $document = $this->entityManager->getRepository(Document::class)->find(123456);

        $accepted = $this->repository->findBy(['document' => $document, 'accepted' => 1]);
        $pending = $this->repository->findBy(['document' => $document, 'accepted' => 0]);

        $this->assertCount(3, $accepted);
        $this->assertCount(2, $pending);
    }

    #[Test]
    public function testFindByDocumentAndSourceFiltersToMatchingRowsOnly(): void
    {
        $this->seedDocumentsUsersAndReferences();

        $document = $this->entityManager->getRepository(Document::class)->find(789012);

        $bibtexImports = $this->repository->findBy([
            'document' => $document,
            'source' => PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT,
        ]);
        $semanticScholar = $this->repository->findBy([
            'document' => $document,
            'source' => PaperReferences::SOURCE_SEMANTICS_SCHOLAR,
        ]);

        $this->assertCount(2, $bibtexImports);
        $this->assertCount(1, $semanticScholar);
    }

    #[Test]
    public function testFindByReturnsEmptyArrayForDocumentWithoutReferences(): void
    {
        $this->seedDocumentsUsersAndReferences();

        $emptyDocument = $this->entityManager->getRepository(Document::class)->find(333333);

        $this->assertSame([], $this->repository->findBy(['document' => $emptyDocument]));
    }

    #[Test]
    public function testUidAssociationResolvesToTheCorrectUserInformations(): void
    {
        $this->seedDocumentsUsersAndReferences();

        $document = $this->entityManager->getRepository(Document::class)->find(123456);
        $references = $this->repository->findBy(['document' => $document], ['referenceOrder' => 'ASC']);

        // ref1 (order 0) is attributed to user 1001, ref2 (order 1) to user 2002.
        $this->assertSame(1001, $references[0]->getUid()?->getId());
        $this->assertSame(2002, $references[1]->getUid()?->getId());

        // ref4 (order 3) has no user attached.
        $this->assertNull($references[3]->getUid());
    }

    #[Test]
    public function testSaveWithFlushTruePersistsReferenceImmediately(): void
    {
        $document = new Document();
        $document->setId(42);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $reference = new PaperReferences();
        $reference->setReference(['raw_reference' => 'New reference']);
        $reference->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
        $reference->setReferenceOrder(0);
        $reference->setAccepted(1);
        $reference->setDocument($document);
        $reference->setUpdatedAt(new \DateTimeImmutable());

        $this->repository->save($reference, true);
        $id = $reference->getId();
        $this->entityManager->clear();

        $persisted = $this->repository->find($id);
        $this->assertInstanceOf(PaperReferences::class, $persisted);
        $this->assertSame('New reference', $persisted->getReference()['raw_reference']);
    }

    #[Test]
    public function testSaveWithFlushFalseDoesNotPersistUntilManualFlush(): void
    {
        $document = new Document();
        $document->setId(43);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $reference = new PaperReferences();
        $reference->setReference(['raw_reference' => 'Pending reference']);
        $reference->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $reference->setReferenceOrder(0);
        $reference->setAccepted(0);
        $reference->setDocument($document);
        $reference->setUpdatedAt(new \DateTimeImmutable());

        $this->repository->save($reference, false);

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM paper_references WHERE document_id = ?', [43])
            ->fetchOne();
        $this->assertSame(0, $count, 'save() with flush=false must not write to the database yet');

        $this->entityManager->flush();

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM paper_references WHERE document_id = ?', [43])
            ->fetchOne();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function testRemoveWithFlushTrueDeletesReferenceImmediately(): void
    {
        $document = new Document();
        $document->setId(44);
        $this->entityManager->persist($document);

        $reference = new PaperReferences();
        $reference->setReference(['raw_reference' => 'To be removed']);
        $reference->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $reference->setReferenceOrder(0);
        $reference->setAccepted(0);
        $reference->setDocument($document);
        $reference->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($reference);
        $this->entityManager->flush();

        $id = $reference->getId();

        $this->repository->remove($reference, true);
        $this->entityManager->clear();

        $this->assertNull($this->repository->find($id));
    }

    #[Test]
    public function testFindReturnsNullForNonExistentReference(): void
    {
        $this->assertNull($this->repository->find(999999));
    }
}
