<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\UserInformationsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for UserInformationsRepository backed by an in-memory SQLite database.
 */
final class UserInformationsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserInformationsRepository $repository;

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

        /** @var UserInformationsRepository $repository */
        $repository = $this->entityManager->getRepository(UserInformations::class);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->entityManager, $this->repository);
    }

    /**
     * Seeds data equivalent to `tests/Fixtures/UserFixtures.php` +
     * `DocumentFixtures.php` + `PaperReferencesFixtures.php`: 2 users, 1
     * document and 5 references distributed across the two users (3 for
     * user 1001, 2 for user 2002).
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
    private function seedUsersDocumentsAndReferences(): void
    {
        $user1 = new UserInformations();
        $user1->setId(1001);
        $user1->setName('Doe');
        $user1->setSurname('John');

        $user2 = new UserInformations();
        $user2->setId(2002);
        $user2->setName('Smith');
        $user2->setSurname('Jane');

        $document = new Document();
        $document->setId(123456);

        $this->entityManager->persist($user1);
        $this->entityManager->persist($user2);
        $this->entityManager->persist($document);

        $references = [
            [PaperReferences::SOURCE_METADATA_GROBID, $user1],
            [PaperReferences::SOURCE_METADATA_EPI_USER, $user2],
            [PaperReferences::SOURCE_METADATA_EPI_USER, $user1],
            [PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, $user1],
            [PaperReferences::SOURCE_SEMANTICS_SCHOLAR, $user2],
        ];
        foreach ($references as $order => [$source, $uid]) {
            $reference = new PaperReferences();
            $reference->setReference(['raw_reference' => "Reference {$order}"]);
            $reference->setSource($source);
            $reference->setAccepted(1);
            $reference->setReferenceOrder($order);
            $reference->setDocument($document);
            $reference->setUid($uid);
            $reference->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($reference);
        }

        $this->entityManager->flush();

        // Detach everything so a subsequent find() re-hydrates UserInformations
        // (and its OneToMany PaperReferences collection) from the database
        // instead of returning the identity-mapped instances built above,
        // whose in-memory collection was never populated from a query.
        $this->entityManager->clear();
    }

    #[Test]
    public function testFindReturnsPersistedUser(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $user = $this->repository->find(1001);

        $this->assertInstanceOf(UserInformations::class, $user);
        $this->assertSame('Doe', $user->getName());
        $this->assertSame('John', $user->getSurname());
    }

    #[Test]
    public function testFindReturnsNullForNonExistentUser(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $this->assertNull($this->repository->find(999999));
    }

    #[Test]
    public function testFindAllReturnsEveryPersistedUser(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $users = $this->repository->findAll();
        $ids = array_map(static fn (UserInformations $user): int => $user->getId(), $users);
        sort($ids);

        $this->assertCount(2, $users);
        $this->assertSame([1001, 2002], $ids);
    }

    #[Test]
    public function testFindOneByNameDiscriminatesBetweenUsers(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $smith = $this->repository->findOneBy(['name' => 'Smith']);
        $doe = $this->repository->findOneBy(['name' => 'Doe']);
        $unknown = $this->repository->findOneBy(['name' => 'NonExistentName']);

        $this->assertNotNull($smith);
        $this->assertSame(2002, $smith->getId());
        $this->assertNotNull($doe);
        $this->assertSame(1001, $doe->getId());
        $this->assertNull($unknown);
    }

    #[Test]
    public function testFindByOrdersUsersBySurnameAscending(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $users = $this->repository->findBy([], ['surname' => 'ASC']);

        // Surnames are 'Jane' and 'John': 'Jane' sorts before 'John'.
        $this->assertSame(['Jane', 'John'], array_map(
            static fn (UserInformations $user) => $user->getSurname(),
            $users
        ));
    }

    #[Test]
    public function testPaperReferencesAssociationDiscriminatesPerUser(): void
    {
        $this->seedUsersDocumentsAndReferences();

        $user1 = $this->repository->find(1001);
        $user2 = $this->repository->find(2002);

        // Per seedUsersDocumentsAndReferences(): user 1001 owns references at
        // order 0, 2 and 3; user 2002 owns references at order 1 and 4.
        $this->assertCount(3, $user1->getPaperReferences());
        $this->assertCount(2, $user2->getPaperReferences());
    }

    #[Test]
    public function testSaveWithFlushTruePersistsUserImmediately(): void
    {
        $user = new UserInformations();
        $user->setId(3003);
        $user->setName('Curie');
        $user->setSurname('Marie');

        $this->repository->save($user, true);
        $this->entityManager->clear();

        $persisted = $this->repository->find(3003);
        $this->assertInstanceOf(UserInformations::class, $persisted);
        $this->assertSame('Curie', $persisted->getName());
    }

    #[Test]
    public function testSaveWithFlushFalseDoesNotPersistUntilManualFlush(): void
    {
        $user = new UserInformations();
        $user->setId(3004);
        $user->setName('Lovelace');
        $user->setSurname('Ada');

        $this->repository->save($user, false);

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM user_informations WHERE id = ?', [3004])
            ->fetchOne();
        $this->assertSame(0, $count, 'save() with flush=false must not write to the database yet');

        $this->entityManager->flush();

        $count = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM user_informations WHERE id = ?', [3004])
            ->fetchOne();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function testRemoveWithFlushTrueDeletesUserImmediately(): void
    {
        $user = new UserInformations();
        $user->setId(3005);
        $user->setName('Hopper');
        $user->setSurname('Grace');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->repository->remove($user, true);
        $this->entityManager->clear();

        $this->assertNull($this->repository->find(3005));
    }
}
