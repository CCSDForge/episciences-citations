<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PurgeRedundantOpenAccessLinksCommand;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeRedundantOpenAccessLinksCommandTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private MockObject $openAccessReferenceEnricher;
    private PurgeRedundantOpenAccessLinksCommand $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(PaperReferencesRepository::class);
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);

        $this->command = new PurgeRedundantOpenAccessLinksCommand($this->entityManager, $this->openAccessReferenceEnricher);
    }

    /**
     * @param array<int, int> $ids
     */
    private function stubReferenceIds(array $ids): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getArrayResult')->willReturn(array_map(static fn (int $id): array => ['id' => $id], $ids));

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('orderBy')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSourceReturnsInvalidStatus(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute(['--source' => 'NOT_A_SOURCE']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testPurgesRedundantOpenAlexLinkAndPersists(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'doi' => '10.1/a',
            'open-access' => ['url' => 'https://doi.org/10.1/a', 'origin' => 'openalex'],
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->with(['id' => [1]])->willReturn([$ref]);

        $this->openAccessReferenceEnricher->method('isRedundantWithDoiLink')
            ->with('10.1/a', 'https://doi.org/10.1/a')
            ->willReturn(true);

        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('purged=1', $tester->getDisplay());
        $this->assertArrayNotHasKey('open-access', $ref->getReference());
        $this->assertNotNull($ref->getUpdatedAt());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDryRunDoesNotPersist(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'doi' => '10.1/a',
            'open-access' => ['url' => 'https://doi.org/10.1/a', 'origin' => 'openalex'],
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->openAccessReferenceEnricher->method('isRedundantWithDoiLink')->willReturn(true);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('purged=1', $tester->getDisplay());
        $this->assertArrayHasKey('open-access', $ref->getReference());
        $this->assertNull($ref->getUpdatedAt());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testSkipsManualOriginLinks(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'doi' => '10.1/a',
            'open-access' => ['url' => 'https://doi.org/10.1/a', 'origin' => 'user'],
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->openAccessReferenceEnricher->expects($this->never())->method('isRedundantWithDoiLink');
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('purged=0', $tester->getDisplay());
        $this->assertArrayHasKey('open-access', $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testNonRedundantOpenAlexLinkIsLeftUntouched(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'doi' => '10.1/a',
            'open-access' => ['url' => 'https://oa.example/x', 'origin' => 'openalex'],
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->openAccessReferenceEnricher->method('isRedundantWithDoiLink')->willReturn(false);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('purged=0', $tester->getDisplay());
    }
}
