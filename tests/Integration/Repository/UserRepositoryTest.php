<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for UserRepository.
 *
 * IMPORTANT: `App\Entity\User` (the CAS-backed security user, see
 * `App\Security\UserProvider`) carries no `#[ORM\Entity]` attribute and has no
 * database table/migration: it is a plain, non-persisted value object built
 * on the fly from the CAS identifier. `UserRepository` nonetheless extends
 * `ServiceEntityRepository<User>`, which only works because doctrine-bundle
 * builds it lazily behind a proxy. Every actual repository call fails as
 * soon as it touches Doctrine's metadata for `User`. These tests pin down
 * that real, current behaviour rather than pretending the class is a
 * functioning repository (see final report for details/recommendation).
 */
final class UserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;

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

        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        $this->repository = new UserRepository($registry);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->repository);
    }

    #[Test]
    public function testConstructorBuildsALazyRepositoryInstanceWithoutTouchingMetadata(): void
    {
        // Construction succeeds because doctrine-bundle wraps the repository
        // in a lazy ServiceEntityRepositoryProxy: no Doctrine metadata lookup
        // happens until an actual repository method is invoked.
        $this->assertInstanceOf(UserRepository::class, $this->repository);
    }

    #[Test]
    public function testFindThrowsBecauseUserEntityIsNotMappedByDoctrine(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches(
            '/Could not find the entity manager for class "App\\\\Entity\\\\User"/'
        );

        $this->repository->find(1);
    }

    #[Test]
    public function testFindAllThrowsBecauseUserEntityIsNotMappedByDoctrine(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->findAll();
    }

    #[Test]
    public function testFindByThrowsBecauseUserEntityIsNotMappedByDoctrine(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->findBy(['username' => 'jdoe']);
    }

    #[Test]
    public function testFindOneByThrowsBecauseUserEntityIsNotMappedByDoctrine(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->findOneBy(['username' => 'jdoe']);
    }
}
