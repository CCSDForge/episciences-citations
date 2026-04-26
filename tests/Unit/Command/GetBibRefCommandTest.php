<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GetBibRefCommand;
use App\Repository\DocumentRepository;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\References;
use App\Services\Semanticsscholar;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GetBibRefCommandTest extends TestCase
{
    private GetBibRefCommand $command;

    protected function setUp(): void
    {
        $doiService = $this->createMock(Doi::class);
        $references = $this->createMock(References::class);
        $semanticsscholar = $this->createMock(Semanticsscholar::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $logger = $this->createMock(LoggerInterface::class);
        $bibtexService = $this->createMock(Bibtex::class);

        $this->command = new GetBibRefCommand(
            $doiService,
            $references,
            $semanticsscholar,
            $entityManager,
            $documentRepository,
            $logger,
            $bibtexService
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    #[DataProvider('urlInTitleProvider')]
    public function testHasUrlInTitle(string $title, bool $expected): void
    {
        $this->assertSame($expected, $this->command->hasUrlInTitle($title));
    }

    public static function urlInTitleProvider(): array
    {
        return [
            'https at start' => ['https://example.com some title', true],
            'http at start' => ['http://example.com some title', true],
            'https in middle' => ['Some title with https://example.com', true],
            'http in middle' => ['Some title with http://example.com', true],
            'no url' => ['Some title without url', false],
            'empty string' => ['', false],
            'partial url' => ['http: is not enough', false],
        ];
    }
}
