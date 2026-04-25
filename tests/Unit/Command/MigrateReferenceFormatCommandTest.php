<?php

namespace App\Tests\Unit\Command;

use App\Command\MigrateReferenceFormatCommand;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateReferenceFormatCommandTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private MigrateReferenceFormatCommand $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(PaperReferencesRepository::class);

        $this->entityManager->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($this->repository);

        $this->command = new MigrateReferenceFormatCommand($this->entityManager);
    }

    #[Test]
    public function testExecute_OldFormat_MigratesAndFlushes(): void
    {
        // Arrange — old format: single-element sequential array containing a JSON string
        $ref = new PaperReferences();
        $ref->setReference([json_encode(['raw_reference' => 'Author. Title. Journal, 2024.', 'doi' => '10.1/x'])]);

        $this->repository->method('findAll')->willReturn([$ref]);
        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Migrated 1 reference(s)', $tester->getDisplay());

        // Verify reference was converted to flat array
        $migrated = $ref->getReference();
        $this->assertArrayHasKey('raw_reference', $migrated);
        $this->assertEquals('Author. Title. Journal, 2024.', $migrated['raw_reference']);
        $this->assertArrayHasKey('doi', $migrated);
        $this->assertEquals('10.1/x', $migrated['doi']);
    }

    #[Test]
    public function testExecute_NewFormat_SkipsAndDoesNotFlush(): void
    {
        // Arrange — new format: associative array (already migrated)
        $ref = new PaperReferences();
        $ref->setReference(['raw_reference' => 'Author. Title. Journal, 2024.']);

        $this->repository->method('findAll')->willReturn([$ref]);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Migrated 0 reference(s)', $tester->getDisplay());
    }

    #[Test]
    public function testExecute_EmptyReference_Skipped(): void
    {
        // Arrange — empty reference (edge case)
        $ref = new PaperReferences();
        $ref->setReference([]);

        $this->repository->method('findAll')->willReturn([$ref]);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('Migrated 0 reference(s)', $tester->getDisplay());
    }

    #[Test]
    public function testExecute_InvalidJsonInOldFormat_SkipsWithWarning(): void
    {
        // Arrange — old format with invalid JSON string inside the array
        $ref = new PaperReferences();
        $ref->setReference(['this is not valid json{{{']);

        $this->repository->method('findAll')->willReturn([$ref]);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Migrated 0 reference(s)', $tester->getDisplay());
    }

    #[Test]
    public function testExecute_MixedBatch_MigratesOnlyOldFormat(): void
    {
        // Arrange — mix of old and new format references
        $oldRef = new PaperReferences();
        $oldRef->setReference([json_encode(['raw_reference' => 'Old format ref'])]);

        $newRef = new PaperReferences();
        $newRef->setReference(['raw_reference' => 'New format ref', 'doi' => '10.1/y']);

        $this->repository->method('findAll')->willReturn([$oldRef, $newRef]);
        $this->entityManager->expects($this->once())->method('persist')->with($oldRef);
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('Migrated 1 reference(s)', $tester->getDisplay());

        // Old ref was migrated to flat array
        $this->assertArrayHasKey('raw_reference', $oldRef->getReference());
        // New ref is unchanged
        $this->assertEquals(['raw_reference' => 'New format ref', 'doi' => '10.1/y'], $newRef->getReference());
    }

    #[Test]
    public function testExecute_NoReferences_ReportsZero(): void
    {
        $this->repository->method('findAll')->willReturn([]);
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('Migrated 0 reference(s)', $tester->getDisplay());
    }
}
