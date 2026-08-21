<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SolrEnrichReferencesCommand;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class SolrEnrichReferencesCommandTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private MockObject $solrReferenceEnricher;
    private SolrEnrichReferencesCommand $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(PaperReferencesRepository::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);

        $this->command = new SolrEnrichReferencesCommand($this->entityManager, $this->solrReferenceEnricher);
    }

    /**
     * @param array<int, int> $ids
     */
    private function stubReferenceIds(array $ids): MockObject
    {
        $query = $this->createStub(Query::class);
        $query->method('getArrayResult')->willReturn(array_map(static fn (int $id): array => ['id' => $id], $ids));

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        return $qb;
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSourceReturnsInvalidStatus(): void
    {
        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['--source' => 'NOT_A_SOURCE']);

        $this->assertSame(Command::INVALID, $statusCode);
        $this->assertStringContainsString('Invalid source', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testNoReferenceIdsReportsZeroStats(): void
    {
        $this->stubReferenceIds([]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->solrReferenceEnricher->expects($this->never())->method('enrichReferences');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString(
            'Solr enrichment: scanned=0 processed=0 enriched=0 cleared=0 unchanged=0 failed=0 batchSize=50 dryRun=no',
            $tester->getDisplay()
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testSkipsReferencesWithoutDoi(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['raw_reference' => 'No doi here']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->with(['id' => [1]])->willReturn([$ref]);

        $this->solrReferenceEnricher->expects($this->never())->method('enrichReferences');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('scanned=1 processed=0 enriched=0 cleared=0 unchanged=0', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyMissingSkipsReferencesWithExistingSolrMetadata(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a', 'status' => 'checked']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->expects($this->never())->method('enrichReferences');

        $tester = new CommandTester($this->command);
        $tester->execute(['--only-missing' => true]);

        $this->assertStringContainsString('scanned=1 processed=0', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testWithoutOnlyMissingReprocessesReferencesWithExistingSolrMetadata(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a', 'status' => 'checked']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')->willReturnArgument(0);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('scanned=1 processed=1', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testVerboseOutputPrintsEnrichedDoiAndDocumentId(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a']);
        $document = new Document();
        $document->setId(7);
        $ref->setDocument($document);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')
            ->willReturnCallback(static fn (array $refs): array => array_map(
                static fn (array $r): array => $r + ['status' => 'checked'],
                $refs
            ));

        $tester = new CommandTester($this->command);
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('Enriched DOI 10.1/a in document 7', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testEnrichesReferenceAndPersistsWithFlushAndClear(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')
            ->willReturnCallback(static fn (array $refs): array => array_map(
                static fn (array $r): array => $r + ['status' => 'checked'],
                $refs
            ));

        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');
        $this->entityManager->expects($this->once())->method('clear');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('enriched=1', $tester->getDisplay());
        $this->assertSame('checked', $ref->getReference()['status']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDryRunSkipsPersistFlushAndClear(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')
            ->willReturnCallback(static fn (array $refs): array => array_map(
                static fn (array $r): array => $r + ['status' => 'checked'],
                $refs
            ));

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->entityManager->expects($this->never())->method('clear');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('enriched=1 cleared=0 unchanged=0', $tester->getDisplay());
        $this->assertStringContainsString('dryRun=yes', $tester->getDisplay());
        // The in-memory entity itself must remain untouched.
        $this->assertArrayNotHasKey('status', $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testUnchangedReferenceIsStillPersistedWhenNotDryRun(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')->willReturnArgument(0);

        // Current behaviour: unchanged references are still persisted (no `continue` in the loop).
        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('enriched=0 cleared=0 unchanged=1', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testClearedWhenSolrFieldsAreRemoved(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a', 'status' => 'checked']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->method('enrichReferences')
            ->willReturnCallback(static fn (array $refs): array => array_map(
                static function (array $r): array {
                    unset($r['status']);
                    return $r;
                },
                $refs
            ));

        $this->entityManager->expects($this->once())->method('persist')->with($ref);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $this->assertStringContainsString('enriched=0 cleared=1 unchanged=0', $tester->getDisplay());
        $this->assertArrayNotHasKey('status', $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testEmptyProcessableBatchNeverCallsEnricherOrFlush(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['raw_reference' => 'No doi']);

        $this->stubReferenceIds([1, 2]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->expects($this->never())->method('enrichReferences');
        $this->entityManager->expects($this->never())->method('flush');
        $this->entityManager->expects($this->never())->method('clear');

        $tester = new CommandTester($this->command);
        $tester->execute([]);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDocidOptionFiltersReferenceIdsByDocument(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getArrayResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->expects($this->once())->method('andWhere')->with('p.document = :docId')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('docId', 5)->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);

        $tester = new CommandTester($this->command);
        $tester->execute(['--docid' => '5']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testSourceOptionFiltersReferenceIdsBySource(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getArrayResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->expects($this->once())->method('andWhere')->with('p.source = :source')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('source', PaperReferences::SOURCE_METADATA_GROBID)->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['--source' => PaperReferences::SOURCE_METADATA_GROBID]);

        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testBatchSizeOptionControlsChunking(): void
    {
        $this->stubReferenceIds([1, 2, 3]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(2);

        $calls = [];
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturnCallback(function (array $criteria) use (&$calls): array {
            $calls[] = $criteria['id'];
            return [];
        });

        $tester = new CommandTester($this->command);
        $tester->execute(['--batch-size' => '2']);

        $this->assertSame([[1, 2], [3]], $calls);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testNumericBatchSizeOptionIsCastAndForwardedToEnricher(): void
    {
        $this->stubReferenceIds([]);
        $this->solrReferenceEnricher->expects($this->once())
            ->method('getEffectiveBatchSize')
            ->with(7)
            ->willReturn(7);

        $tester = new CommandTester($this->command);
        $tester->execute(['--batch-size' => '7']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testNonNumericBatchSizeOptionFallsBackToServiceDefault(): void
    {
        $this->stubReferenceIds([]);
        $this->solrReferenceEnricher->expects($this->once())
            ->method('getEffectiveBatchSize')
            ->with(null)
            ->willReturn(50);

        $tester = new CommandTester($this->command);
        $tester->execute(['--batch-size' => 'not-a-number']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testForceOptionIsForwardedToEnricher(): void
    {
        $ref = new PaperReferences();
        $ref->setReference(['doi' => '10.1/a']);

        $this->stubReferenceIds([1]);
        $this->solrReferenceEnricher->method('getEffectiveBatchSize')->willReturn(50);
        $this->entityManager->method('getRepository')->willReturn($this->repository);
        $this->repository->method('findBy')->willReturn([$ref]);

        $this->solrReferenceEnricher->expects($this->once())
            ->method('enrichReferences')
            ->with($this->anything(), true, 50)
            ->willReturnArgument(0);

        $tester = new CommandTester($this->command);
        $tester->execute(['--force' => true]);
    }
}
