<?php

namespace App\Controller;

use App\Entity\Document;
use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The bearer-token-authenticated public HTTP API counterpart of ExtractController,
 * kept in its own class so neither exceeds Sonar's 20-method-per-class limit (S1448).
 */
class ApiExtractController extends AbstractController
{
    public function __construct(
        private readonly Grobid $grobid,
        private readonly References $references,
        private readonly Episciences $episciences,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws JsonException
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    #[Route('/api/extract', name: 'app_api_extract', methods: ['GET'])]
    public function apiExtract(Request $request): JsonResponse
    {
        $tokenError = $this->validateApiToken($request);
        if ($tokenError !== null) {
            return $tokenError;
        }

        $url = $this->resolveApiExtractUrl($request);
        if ($url instanceof JsonResponse) {
            return $url;
        }

        return $this->resolveApiExtractDocIdAndExtract($request, $url);
    }

    private function validateApiToken(Request $request): ?JsonResponse
    {
        return $this->isValidApiToken($request)
            ? null
            : new JsonResponse(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function resolveApiExtractUrl(Request $request): string|JsonResponse
    {
        $url = (string) $request->query->get('url', '');
        if ($url === '') {
            return new JsonResponse(['success' => false, 'error' => 'Missing required parameter: url'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->episciences->isAllowedUrl($url)) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid URL: only http(s) URLs on an allowed Episciences host are accepted'],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $url;
    }

    private function resolveApiExtractDocIdAndExtract(Request $request, string $url): JsonResponse
    {
        $docId = $this->resolveApiExtractDocId($request, $url);
        if ($docId instanceof JsonResponse) {
            return $docId;
        }

        return $this->performApiExtraction($url, $docId);
    }

    private function resolveApiExtractDocId(Request $request, string $url): int|JsonResponse
    {
        $docIdParam = $request->query->get('docid');
        $docId = $docIdParam !== null
            ? (int) $docIdParam
            : (int) $this->episciences->getDocIdFromUrl($url);

        if ($docId === 0) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Could not determine a document ID. Provide a docid parameter or use an Episciences URL.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $docId;
    }

    private function performApiExtraction(string $url, int $docId): JsonResponse
    {
        $referenceCount = $this->grobid->countAllReferencesFromDB($docId);
        if ($referenceCount > 0) {
            return new JsonResponse(['success' => true, 'docId' => $docId, 'alreadyExtracted' => true, 'referenceCount' => $referenceCount]);
        }

        return $this->downloadAndExtractViaApi($url, $docId);
    }

    private function downloadAndExtractViaApi(string $url, int $docId): JsonResponse
    {
        $getPdf = $this->episciences->downloadPdf($url, $docId);
        if (is_array($getPdf)) {
            $status = $getPdf['status'] === 404 ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_GATEWAY;
            return new JsonResponse(['success' => false, 'error' => $getPdf['message']], $status);
        }

        $insertRef = $this->grobid->insertReferences($docId, $this->getParameter('deposit_pdf') . '/' . $docId . '.pdf');
        if ($insertRef === false) {
            if (!$this->references->getDocument($docId) instanceof Document) {
                $this->references->createDocumentId($docId);
            }
            return new JsonResponse(['success' => false, 'docId' => $docId, 'error' => 'No references found in the PDF'], Response::HTTP_OK);
        }

        return new JsonResponse(['success' => true, 'docId' => $docId, 'alreadyExtracted' => false]);
    }

    private function isValidApiToken(Request $request): bool
    {
        $expected = (string) $this->getParameter('api_extract_token');
        if ($expected === '' || $expected === 'changeme') {
            $this->logger->warning('API_EXTRACT_TOKEN is not configured — /api/extract is disabled');
            return false;
        }
        return hash_equals('Bearer ' . $expected, (string) $request->headers->get('Authorization'));
    }
}
