<?php

namespace App\Tests\Unit\Services;

use App\Services\Episciences;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EpisciencesTest extends TestCase
{
    private Episciences $service;
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private ContainerBagInterface $params;
    private LoggerInterface $logger;
    private string $pdfFolder;
    private string $apiRight = 'http://mock-api';
    private bool $forceHttp = false;

    protected function setUp(): void
    {
        // Nettoyer le dossier PDF AVANT chaque test
        $this->pdfFolder = sys_get_temp_dir() . '/test_pdf_cache/';
        if (is_dir($this->pdfFolder)) {
            $files = glob($this->pdfFolder . '*.pdf');
            foreach ($files as $file) {
                unlink($file);
            }
        }

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->params = $this->createMock(ContainerBagInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new Episciences(
            $this->entityManager,
            $this->httpClient,
            $this->params,
            $this->pdfFolder,
            $this->apiRight,
            $this->logger,
            $this->forceHttp
        );
    }

    protected function tearDown(): void
    {
        // Nettoyer le dossier PDF après chaque test
        if (is_dir($this->pdfFolder)) {
            $files = glob($this->pdfFolder . '*.pdf');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->pdfFolder);
        }
    }

    #[Test]
    public function testGetDocIdFromUrl_ValidUrl_ExtractsId(): void
    {
        // Arrange - la regex cherche /(\d+)(?:/|$) donc le nombre doit être suivi de / ou fin de chaîne
        $testCases = [
            'https://episciences.org/journal/123456' => '123456',
            'https://episciences.org/journal/123456/' => '123456',
            'http://test.org/article/789/view' => '789',
            'https://episciences.org/999' => '999',
            'https://episciences.org/999/' => '999',
        ];

        foreach ($testCases as $url => $expectedId) {
            // Act
            $result = $this->service->getDocIdFromUrl($url);

            // Assert
            $this->assertEquals($expectedId, $result, "Failed for URL: $url");
        }
    }

    #[Test]
    public function testGetDocIdFromUrl_InvalidUrl_ReturnsEmpty(): void
    {
        // Arrange
        $invalidUrls = [
            'https://episciences.org/journal',
            'https://episciences.org/',
            'invalid-url',
            '',
        ];

        foreach ($invalidUrls as $url) {
            // Act
            $result = $this->service->getDocIdFromUrl($url);

            // Assert
            $this->assertEquals('', $result, "Failed for URL: $url");
        }
    }

    #[Test]
    public function testManageHttpErrorMessagePDF_404_ReturnsCustomMessage(): void
    {
        // Arrange
        $status = 404;
        $originalMessage = 'Not Found';

        // Act
        $result = $this->service->manageHttpErrorMessagePDF($status, $originalMessage);

        // Assert
        $this->assertEquals('PDF not found at the destined address', $result);
    }

    #[Test]
    public function testManageHttpErrorMessagePDF_OtherStatus_ReturnsOriginalMessage(): void
    {
        // Arrange
        $status = 500;
        $originalMessage = 'Internal Server Error';

        // Act
        $result = $this->service->manageHttpErrorMessagePDF($status, $originalMessage);

        // Assert
        $this->assertEquals($originalMessage, $result);
    }

    #[Test]
    public function testGetPaperPDF_FileAlreadyExists_ReturnsTrue(): void
    {
        // Arrange
        $url = 'https://episciences.org/pdf/123456.pdf';

        // Create folder and existing PDF file
        if (!is_dir($this->pdfFolder)) {
            mkdir($this->pdfFolder, 0777, true);
        }
        file_put_contents($this->pdfFolder . '123456.pdf', 'existing PDF content');

        // HTTP client should NOT be called (file already exists)
        $this->httpClient->expects($this->never())
            ->method('request');

        // Act
        $result = $this->service->getPaperPDF($url);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testGetPaperPDF_Success_DownloadsAndCachesPdf(): void
    {
        // Arrange
        $url = 'https://episciences.org/pdf/123456/';  // Add / so getDocIdFromUrl works
        $pdfContent = 'mock PDF binary content';

        // Mock HTTP response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($pdfContent);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $url, [
                'headers' => [
                    'Accept' => 'application/octet-stream'
                ]
            ])
            ->willReturn($response);

        // Act
        $result = $this->service->getPaperPDF($url);

        // Assert
        $this->assertTrue($result);

        // Verify that file was created
        $this->assertFileExists($this->pdfFolder . '123456.pdf');
        $this->assertEquals($pdfContent, file_get_contents($this->pdfFolder . '123456.pdf'));
    }

    #[Test]
    public function testGetPaperPDF_HttpError404_ReturnsErrorArray(): void
    {
        // Arrange
        $url = 'https://episciences.org/pdf/999999/';  // Add / so getDocIdFromUrl works

        // Mock HTTP exception
        $exception = new class('Not Found', 404) extends \Exception implements ClientExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                throw new \RuntimeException('Not implemented');
            }
        };

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Logger should be called for 404
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('PDF NOT FOUND ON EPISCIENCES', ['DOCID' => '999999']);

        // Act
        $result = $this->service->getPaperPDF($url);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals(404, $result['status']);
        $this->assertStringContainsString('PDF not found', $result['message']);
    }

    #[Test]
    public function testGetPaperPDF_ForceHttp_ConvertsHttpsToHttp(): void
    {
        // Arrange - créer service avec forceHttp = true
        $serviceWithForceHttp = new Episciences(
            $this->entityManager,
            $this->httpClient,
            $this->params,
            $this->pdfFolder,
            $this->apiRight,
            $this->logger,
            true // forceHttp activé
        );

        $url = 'https://episciences.org/pdf/888888/';  // Add / so getDocIdFromUrl works
        $expectedUrl = 'http://episciences.org/pdf/888888/';  // Should be converted to HTTP
        $pdfContent = 'mock PDF content';

        // Mock HTTP response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($pdfContent);

        // Vérifier que l'URL est convertie en HTTP
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $expectedUrl, $this->anything())
            ->willReturn($response);

        // Act
        $result = $serviceWithForceHttp->getPaperPDF($url);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testGetRightUser_Allowed_ReturnsTrue(): void
    {
        // Arrange
        $docId = 123456;
        $uid = 789;
        $expectedUrl = $this->apiRight . "/api/users/$uid/is-allowed-to-edit-citations?documentId=$docId";

        // Mock HTTP response returning "true" (as string)
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('true');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $expectedUrl)
            ->willReturn($response);

        // Act
        $result = $this->service->getRightUser($docId, $uid);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testGetRightUser_NotAllowed_ReturnsFalse(): void
    {
        // Arrange
        $docId = 123456;
        $uid = 789;

        // Mock HTTP response returning "false" (as string)
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('false');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // Act
        $result = $this->service->getRightUser($docId, $uid);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testGetRightUser_HttpError_ReturnsFalse(): void
    {
        // Arrange
        $docId = 123456;
        $uid = 789;

        // Mock HTTP exception
        $exception = new class('Internal Server Error', 500) extends \Exception implements ClientExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                throw new \RuntimeException('Not implemented');
            }
        };

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Act
        $result = $this->service->getRightUser($docId, $uid);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testPutPdfInCache_Success_WritesPdfFile(): void
    {
        // Arrange
        $name = '123456';
        $pdfContent = 'test PDF content';

        // Créer le dossier s'il n'existe pas
        if (!is_dir($this->pdfFolder)) {
            mkdir($this->pdfFolder, 0777, true);
        }

        // Act
        $result = $this->service->putPdfInCache($name, $pdfContent);

        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($this->pdfFolder . $name . '.pdf');
        $this->assertEquals($pdfContent, file_get_contents($this->pdfFolder . $name . '.pdf'));
    }
}
