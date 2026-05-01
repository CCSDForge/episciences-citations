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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GetBibRefCommandTest extends TestCase
{
    private GetBibRefCommand $command;

    protected function setUp(): void
    {
        $doiService             = $this->createStub(Doi::class);
        $references             = $this->createStub(References::class);
        $semanticsScholarImporter = $this->createStub(SemanticScholarImporter::class);
        $entityManager          = $this->createStub(EntityManagerInterface::class);
        $documentRepository     = $this->createStub(DocumentRepository::class);
        $logger                 = $this->createStub(LoggerInterface::class);
        $solrReferenceEnricher  = $this->createStub(SolrReferenceEnricher::class);
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
