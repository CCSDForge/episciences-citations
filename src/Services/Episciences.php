<?php
namespace App\Services;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Episciences {

    public function __construct(private readonly HttpClientInterface $client,
                                private readonly string $pdfFolder,
                                private readonly string $apiRight,
                                private readonly LoggerInterface $logger,
                                private readonly bool $forceHttp = false)
    {
    }

    public function getPaperPDF(string $url): array|bool
    {
        $docId = $this->getDocIdFromUrl($url);
        if ($docId === '') {
            return false;
        }
        return $this->downloadPdf($url, (int) $docId);
    }

    /**
     * Resolves an Episciences article URL to its direct PDF download URL.
     *
     * Known patterns:
     *   /{journal}/articles/{id}[/] → /download   (e.g. transformations.episciences.org)
     *   /{id}[/]                    → /pdf         (e.g. lmcs.episciences.org)
     */
    public function resolvePdfUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $base = rtrim($url, '/');

        if (preg_match('#/articles/\d+/?$#', $path)) {
            return $base . '/download';
        }

        if (preg_match('#^/\d+/?$#', $path)) {
            return $base . '/pdf';
        }

        return $url;
    }

    public function downloadPdf(string $url, int $docId): array|bool
    {
        $this->createDirDataPdf();

        $url = $this->resolvePdfUrl($url);

        if ($this->forceHttp && str_contains($url, 'episciences.org') && str_starts_with($url, 'https://')) {
            $url = str_replace('https://', 'http://', $url);
        }

        if (file_exists($this->pdfFolder . $docId . '.pdf')) {
            return true;
        }

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['Accept' => 'application/octet-stream'],
            ])->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            $this->logger->warning('PDF download failed', ['url' => $url, 'docId' => $docId, 'code' => $e->getCode()]);
            return ['status' => $e->getCode(), 'message' => $this->manageHttpErrorMessagePDF($e->getCode(), $e->getMessage())];
        }

        return $this->putPdfInCache((string) $docId, $response);
    }
    public function putPdfInCache(string $name, $response): bool
    {
        $fp = fopen($this->pdfFolder.$name.'.pdf', 'wb');
        if (fwrite($fp, (string) $response) === false) {
            return false;
        }
        fclose($fp);
        return true;

    }

    public function manageHttpErrorMessagePDF(int $status, string $message): string
    {

        if ($status === 404) {
            return "PDF not found at the destined address";
        }
        return $message;
    }

    public function getDocIdFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if (preg_match('#/(\d+)/?$#', $path, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getRightUser(string $docId, string $uid): bool {
        try {
            $response = $this->client->request('GET', $this->apiRight."/api/users/" . $uid . "/is-allowed-to-edit-citations?documentId=" . $docId)->getContent();
            // Convert string response ("true"/"false") to boolean
            return $response === 'true';
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface) {
            return false;
        }
    }

    public function createDirDataPdf(): void
    {
        if (!file_exists($this->pdfFolder) && !mkdir($concurrentDirectory = $this->pdfFolder) && !is_dir($concurrentDirectory)) {
            exit();
        }
    }
}
