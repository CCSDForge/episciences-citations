<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\StripDuplicateDoisCommand;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StripDuplicateDoisCommandTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private StripDuplicateDoisCommand $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(PaperReferencesRepository::class);
        $this->command = new StripDuplicateDoisCommand($this->entityManager);
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
    public function testStripsTrailingDoiAndPersists(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            'doi' => '10.46298/jtcam.11335',
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->with(['id' => [1]])->willReturn([$ref]);

        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('stripped=1', $tester->getDisplay());
        $this->assertSame('Author, A. (2024). Title. Journal.', $ref->getReference()['raw_reference']);
        $this->assertNotNull($ref->getUpdatedAt());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDryRunDoesNotPersist(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            'doi' => '10.46298/jtcam.11335',
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('stripped=1', $tester->getDisplay());
        $this->assertStringContainsString('https://doi.org', $ref->getReference()['raw_reference']);
        $this->assertNull($ref->getUpdatedAt());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testReferenceWithoutTrailingDoiIsLeftUntouched(): void
    {
        $ref = new PaperReferences();
        $ref->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal.',
            'doi' => '10.46298/jtcam.11335',
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('stripped=0', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testReferenceWithoutStructuredDoiIsNeverStripped(): void
    {
        // Regression test: a raw_reference ending in a DOI-looking URL but with no
        // top-level 'doi' key (a normal GROBID extraction gap) must be left alone —
        // stripping it here would erase the reference's only DOI representation
        // with no way to recover it.
        $ref = new PaperReferences();
        $ref->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('scanned=1', $tester->getDisplay());
        $this->assertStringContainsString('stripped=0', $tester->getDisplay());
        $this->assertSame(
            'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            $ref->getReference()['raw_reference']
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testReferenceWithBlankDoiIsNeverStripped(): void
    {
        // A blank/whitespace-only 'doi' value must be treated the same as "no DOI".
        $ref = new PaperReferences();
        $ref->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            'doi' => '  ',
        ]);

        $this->stubReferenceIds([1]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('stripped=0', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testScannedAndStrippedCountsAccumulateAcrossBatches(): void
    {
        // Regression test: scanned/stripped counts must aggregate across multiple
        // batches now that processBatch() returns its own stats instead of
        // mutating a shared by-reference accumulator.
        $strippableRef = new PaperReferences();
        $strippableRef->setReference([
            'raw_reference' => 'Author, A. (2024). Title. Journal. https://doi.org/10.46298/jtcam.11335',
            'doi' => '10.46298/jtcam.11335',
        ]);
        $untouchedRef = new PaperReferences();
        $untouchedRef->setReference([
            'raw_reference' => 'Author, B. (2023). Other Title. Journal.',
            'doi' => '10.1234/other',
        ]);

        $this->stubReferenceIds([1, 2]);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')
            ->willReturnMap([
                [['id' => [1]], null, [$strippableRef]],
                [['id' => [2]], null, [$untouchedRef]],
            ]);

        $tester = new CommandTester($this->command);
        $tester->execute(['--batch-size' => 1]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('scanned=2', $tester->getDisplay());
        $this->assertStringContainsString('stripped=1', $tester->getDisplay());
    }
}
