<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GetBibRefCommand;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Services\Doi;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

class GetBibRefCommandTest extends TestCase
{
    private MockObject $doiService;
    private MockObject $references;
    private MockObject $semanticsScholarImporter;
    private MockObject $entityManager;
    private MockObject $documentRepository;
    private MockObject $logger;
    private MockObject $solrReferenceEnricher;
    private MockObject $openAccessReferenceEnricher;
    private GetBibRefCommand $command;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->doiService = $this->createMock(Doi::class);
        $this->references = $this->createMock(References::class);
        $this->semanticsScholarImporter = $this->createMock(SemanticScholarImporter::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReference')->willReturnArgument(0);
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);
        $this->openAccessReferenceEnricher->method('enrichReference')->willReturnArgument(0);

        $this->command = new GetBibRefCommand(
            $this->doiService,
            $this->references,
            $this->semanticsScholarImporter,
            $this->entityManager,
            $this->documentRepository,
            $this->logger,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    private function createCsvFile(string $header, string ...$rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'getbibref_test_') . '.csv';
        $this->tmpFiles[] = $path;
        file_put_contents($path, implode("\n", [$header, ...$rows]) . "\n");

        return $path;
    }

    private function stubUserRepository(?UserInformations $user): void
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($user);
        $this->entityManager->method('getRepository')->with(UserInformations::class)->willReturn($repo);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testCommandCanBeInstantiated(): void
    {
        $this->assertInstanceOf(GetBibRefCommand::class, $this->command);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCsvGroupsRowsByDocIdAndTrimsValues(): void
    {
        $path = $this->createCsvFile(
            'docid, doi ',
            ' 1 , 10.1000/a ',
            '1,10.1000/b',
            '2,10.1000/c'
        );

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn($path);

        $result = $this->command->processCsv($input);

        $this->assertSame([1, 2], array_keys($result));
        $this->assertCount(2, $result['1']);
        $this->assertCount(1, $result['2']);
        // Values and keys are trimmed
        $this->assertSame('10.1000/a', $result['1'][0]['doi']);
        $this->assertSame('10.1000/b', $result['1'][1]['doi']);
        $this->assertSame('10.1000/c', $result['2'][0]['doi']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCslToGetRefWithEmptyCslReturnsNullAndWritesNothing(): void
    {
        $output = new BufferedOutput();

        $this->doiService->expects($this->never())->method('retrieveReferencesFromCsl');

        $result = $this->command->processCslToGetRef('', [], [], $output, 0, 1);

        $this->assertNull($result);
        $this->assertSame('', $output->fetch());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCslToGetRefSkipsReferenceAlreadyInDbByDoi(): void
    {
        $output = new BufferedOutput();

        $this->doiService->method('retrieveReferencesFromCsl')->willReturn([
            ['doi' => '10.1/a', 'raw_reference' => 'Some existing text'],
        ]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->command->processCslToGetRef('{"reference":[]}', ['10.1/a'], [], $output, 0, 1);

        $this->assertStringContainsString('Already in Database', $output->fetch());
        $this->assertSame(['doi' => '10.1/a', 'raw_reference' => 'Some existing text'], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCslToGetRefSkipsReferenceAlreadyInDbByRawReference(): void
    {
        $output = new BufferedOutput();

        $this->doiService->method('retrieveReferencesFromCsl')->willReturn([
            ['raw_reference' => 'Some existing text'],
        ]);

        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->command->processCslToGetRef(
            '{"reference":[]}',
            [],
            [serialize('Some existing text')],
            $output,
            0,
            1
        );

        $this->assertStringContainsString('Already in Database', $output->fetch());
        $this->assertSame(['raw_reference' => 'Some existing text'], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessCslToGetRefInsertsNewReference(): void
    {
        $output = new BufferedOutput();

        $this->doiService->method('retrieveReferencesFromCsl')->willReturn([
            ['doi' => '10.1/new', 'raw_reference' => 'Brand new text'],
        ]);

        $this->stubUserRepository(null);
        $document = new Document();
        $document->setId(1);
        $this->references->method('getDocument')->willReturn($document);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->command->processCslToGetRef('{"reference":[]}', [], [], $output, 0, 1);

        $this->assertStringContainsString('New inserted =>', $output->fetch());
        $this->assertSame(['doi' => '10.1/new', 'raw_reference' => 'Brand new text'], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertRefInDbCreatesNewUserWhenNoneExists(): void
    {
        $this->stubUserRepository(null);
        $document = new Document();
        $document->setId(42);
        $this->references->method('getDocument')->willReturn($document);
        $this->references->expects($this->never())->method('createDocumentId');

        $this->solrReferenceEnricher->expects($this->once())->method('enrichReference')->willReturnArgument(0);
        $this->openAccessReferenceEnricher->expects($this->once())->method('enrichReference')->willReturnArgument(0);

        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(PaperReferences::class));
        $this->entityManager->expects($this->once())->method('flush');

        [$ref, $counter] = $this->command->insertRefInDb(['doi' => '10.1/x', 'raw_reference' => 'x'], 5, 42);

        $this->assertSame(6, $counter);
        $this->assertSame(5, $ref->getReferenceOrder());
        $this->assertSame(0, $ref->getAccepted());
        $this->assertSame(PaperReferences::SOURCE_METADATA_EPI_USER, $ref->getSource());
        $this->assertSame($document, $ref->getDocument());
        $this->assertNotNull($ref->getUid());
        $this->assertSame(666, $ref->getUid()->getId());
        $this->assertSame('Episciences', $ref->getUid()->getSurname());
        $this->assertSame('System', $ref->getUid()->getName());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertRefInDbReusesExistingUserWhenFound(): void
    {
        $existingUser = new UserInformations();
        $existingUser->setId(666);
        $existingUser->setName('Real');
        $existingUser->setSurname('User');
        $this->stubUserRepository($existingUser);

        $document = new Document();
        $document->setId(1);
        $this->references->method('getDocument')->willReturn($document);

        [$ref] = $this->command->insertRefInDb(['doi' => '10.1/x'], 0, 1);

        $this->assertSame($existingUser, $ref->getUid());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertRefInDbCreatesDocumentWhenMissing(): void
    {
        $this->stubUserRepository(null);
        $document = new Document();
        $document->setId(99);

        $this->references->method('getDocument')->willReturnOnConsecutiveCalls(null, $document);
        $this->references->expects($this->once())->method('createDocumentId')->with(99);

        [$ref] = $this->command->insertRefInDb(['doi' => '10.1/x'], 0, 99);

        $this->assertSame($document, $ref->getDocument());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertRefInDbUsesCustomSource(): void
    {
        $this->stubUserRepository(null);
        $document = new Document();
        $document->setId(1);
        $this->references->method('getDocument')->willReturn($document);

        [$ref] = $this->command->insertRefInDb(['doi' => '10.1/x'], 0, 1, PaperReferences::SOURCE_METADATA_GROBID);

        $this->assertSame(PaperReferences::SOURCE_METADATA_GROBID, $ref->getSource());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteHappyPathWithNewDocumentReturnsSuccess(): void
    {
        $path = $this->createCsvFile('docid,doi', '1,10.1/z');

        $this->documentRepository->method('find')->with(1)->willReturn(null);
        $this->doiService->method('getCsl')->with('10.1/z')->willReturn('{"reference":[]}');
        $this->doiService->method('retrieveReferencesFromCsl')->willReturn([]);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['csv' => $path]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('START SCRIPT', $tester->getDisplay());
        $this->assertStringContainsString('END SCRIPT', $tester->getDisplay());
        $this->assertStringContainsString('SEARCH FOR THIS => 1', $tester->getDisplay());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithApiS2OptionDelegatesToSemanticScholarImporter(): void
    {
        $path = $this->createCsvFile('docid,doi', '2,10.1/s2');

        $this->documentRepository->method('find')->with(2)->willReturn(null);
        $this->doiService->expects($this->never())->method('getCsl');
        $this->semanticsScholarImporter->expects($this->once())
            ->method('importByPaperId')
            ->with('DOI:10.1/s2', 2, 0);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['csv' => $path, '--api' => 'S2']);

        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWithExistingDocumentSkipsDuplicateAndInsertsNewReference(): void
    {
        $path = $this->createCsvFile('docid,doi', '1,10.1/dup', '1,10.1/new');

        $existingRef = new PaperReferences();
        $existingRef->setReference(['doi' => '10.1/dup', 'raw_reference' => 'Existing text']);
        $existingRef->setSource(PaperReferences::SOURCE_METADATA_GROBID);

        $document = new Document();
        $document->setId(1);
        $document->addPaperReference($existingRef);

        $this->documentRepository->method('find')->with(1)->willReturn($document);
        $this->doiService->method('getCsl')->willReturn('{"reference":[]}');
        $this->doiService->method('retrieveReferencesFromCsl')->willReturnOnConsecutiveCalls(
            [['doi' => '10.1/dup', 'raw_reference' => 'Existing text']],
            [['doi' => '10.1/new', 'raw_reference' => 'Brand new text']]
        );

        $this->stubUserRepository(null);
        $this->references->method('getDocument')->willReturn($document);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute(['csv' => $path]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('Already in Database', $tester->getDisplay());
        $this->assertStringContainsString('New inserted =>', $tester->getDisplay());
    }
}
