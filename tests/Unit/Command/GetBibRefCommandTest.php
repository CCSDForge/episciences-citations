<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GetBibRefCommand;
use App\Repository\DocumentRepository;
use App\Services\Doi;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GetBibRefCommandTest extends TestCase
{
    private GetBibRefCommand $command;

    #[AllowMockObjectsWithoutExpectations]
    protected function setUp(): void
    {
        $doiService             = $this->createMock(Doi::class);
        $references             = $this->createMock(References::class);
        $semanticsScholarImporter = $this->createMock(SemanticScholarImporter::class);
        $entityManager          = $this->createMock(EntityManagerInterface::class);
        $documentRepository     = $this->createMock(DocumentRepository::class);
        $logger                 = $this->createMock(LoggerInterface::class);
        $solrReferenceEnricher  = $this->createMock(SolrReferenceEnricher::class);
        $solrReferenceEnricher->method('enrichReference')->willReturnArgument(0);

        $this->command = new GetBibRefCommand(
            $doiService,
            $references,
            $semanticsScholarImporter,
            $entityManager,
            $documentRepository,
            $logger,
            $solrReferenceEnricher
        );
    }

    #[Test]
    public function testCommandCanBeInstantiated(): void
    {
        $this->assertInstanceOf(GetBibRefCommand::class, $this->command);
    }
}
