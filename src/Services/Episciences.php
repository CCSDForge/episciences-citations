<?php
namespace App\Services;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Episciences {

    private const array DEFAULT_ALLOWED_HOSTS = ['episciences.org'];

    public function __construct(private readonly HttpClientInterface $client,
                                private readonly string $pdfFolder,
                                private readonly string $apiRight,
                                private readonly LoggerInterface $logger,
                                private readonly bool $forceHttp = false,
                                private readonly string $allowedHosts = '')
    {
    }

    public function isAllowedUrl(string $url): bool
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = strtolower(rawurldecode(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        $allowed = $this->allowedHosts !== ''
            ? array_map(trim(...), explode(',', $this->allowedHosts))
            : self::DEFAULT_ALLOWED_HOSTS;
        return array_any($allowed, fn($pattern): bool => $host === $pattern || str_ends_with($host, '.' . $pattern));
    }

    /**
     * @return array{status: int, message: string}|bool
     */
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

    /**
     * @return array{status: int, message: string}|bool
     */
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

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        // Zenodo rejects specific MIME types like 'application/octet-stream'; */* is required
        $accept = str_ends_with($host, 'zenodo.org') ? '*/*' : 'application/octet-stream';

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['Accept' => $accept],
            ])->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            $this->logger->warning('PDF download failed', ['url' => $url, 'docId' => $docId, 'code' => $e->getCode()]);
            return ['status' => $e->getCode(), 'message' => $this->manageHttpErrorMessagePDF($e->getCode(), $e->getMessage())];
        }

        return $this->putPdfInCache((string) $docId, $response);
    }
    public function putPdfInCache(string $name, string $response): bool
    {
        $fp = fopen($this->pdfFolder.$name.'.pdf', 'wb');
        if (fwrite($fp, $response) === false) {
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
        $path = preg_replace('#/(pdf|download)/?$#i', '', $path) ?? $path;
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
