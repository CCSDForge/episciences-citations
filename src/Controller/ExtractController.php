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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the "kick off a PDF citation extraction" pipeline: triggering GROBID,
 * showing the processing page while it runs, and serving the cached PDF.
 *
 * The reference-editing UI lives in ReferenceEditController, the public HTTP
 * API in ApiExtractController, and the Semantic Scholar import action in
 * SemanticScholarImportController — kept separate to stay under Sonar's
 * 20-method-per-class limit (S1448).
 */
class ExtractController extends AbstractController
{
    private const string NO_REFERENCE_FOUND_MESSAGE = 'No reference found in the PDF';

    public function __construct(
        private readonly Grobid $grobid,
        private readonly References $references,
        private readonly Episciences $episciences,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
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
    #[Route('/extract', name: 'app_extract')]
    #[IsGranted('ROLE_USER')]
    public function extract(Request $request): RedirectResponse|Response
    {
        $rawUrl = (string) $request->query->get('url', '');
        if (!$this->episciences->isAllowedUrl($rawUrl)) {
            throw $this->createAccessDeniedException('URL hostname not allowed');
        }

        $docId = (int) $this->episciences->getDocIdFromUrl($rawUrl);
        $getPdf = $this->episciences->getPaperPDF($rawUrl);

        $this->logger->info('Extracting for docid ', ['DocId' => $docId]);
        $this->logger->info('Extracting for pdf ', ['PDF' => $getPdf]);

        if ($request->query->get('exportbib') === "1") {
            return $this->exportBibRedirect($docId);
        }
        if (isset($getPdf['status']) && $getPdf['status'] === 404) {
            $this->logger->error('Unable to get PDF from Episciences ', ['PDF' => $getPdf]);
            throw $this->createNotFoundException('Unable to get PDF from Episciences');
        }

        $request->getSession()->set('openModalClose', 0);

        if ($this->references->documentAlreadyExtracted($docId)) {
            return $this->handleAlreadyExtracted($docId, $request);
        }

        $this->logger->info('Insert references for the first time ', ['DocId' => $docId]);
        return $this->extractReferencesOrShowProcessing($docId, $request, true)
            ?? $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
    }

    private function exportBibRedirect(int $docId): RedirectResponse
    {
        if (!$this->references->getDocument($docId) instanceof Document) {
            $this->references->createDocumentId($docId);
        }

        return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
    }

    private function handleAlreadyExtracted(int $docId, Request $request): RedirectResponse|Response
    {
        if ($request->query->has('rextract')) {
            $this->logger->info('Rextract => ', ['Rextract - DocId' => $docId]);
            return $this->extractReferencesOrShowProcessing($docId, $request, false)
                ?? $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
        }

        if ($this->references->getReferences($docId, 'all') === []) {
            // Refs absent — attempt (re)insertion: uses cache if available, calls GROBID otherwise
            $this->logger->info('Document exists with no refs — retrying extraction', ['DocId' => $docId]);
            $processing = $this->extractReferencesOrShowProcessing($docId, $request, false);
            if ($processing !== null) {
                return $processing;
            }
        }

        $this->logger->info('Get in database document refs already extracted ', ['DocId' => $docId]);
        return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
    }

    /**
     * Ensures references are inserted for $docId, showing the processing page while GROBID has no cached result yet.
     *
     * @throws JsonException
     */
    private function extractReferencesOrShowProcessing(int $docId, Request $request, bool $createStubOnFailure): ?Response
    {
        if (!$this->grobid->hasCachedReferences($docId)) {
            return $this->renderProcessingPage($docId, $request);
        }

        $insertRef = $this->grobid->insertReferences($docId, $this->getParameter('deposit_pdf') . '/' . $docId . '.pdf');
        if ($insertRef === false) {
            $this->addFlash('notice', $this->translator->trans(self::NO_REFERENCE_FOUND_MESSAGE));
            if ($createStubOnFailure && !$this->references->getDocument($docId) instanceof Document) {
                $this->references->createDocumentId($docId);
            }
        }

        return null;
    }

    private function renderProcessingPage(int $docId, Request $request): Response
    {
        return $this->render('extract/processing.html.twig', [
            'extractRunUrl' => $this->generateUrl('app_extract_run', ['docId' => $docId]),
            'viewRefUrl'    => $this->generateUrl('app_view_ref', ['docId' => $docId, '_locale' => $request->getLocale()]),
        ]);
    }

    #[Route('/extract/run', name: 'app_extract_run')]
    public function extractRun(Request $request): JsonResponse
    {
        $docId = (int) $request->query->get('docId');
        $insertRef = $this->grobid->insertReferences(
            $docId,
            $this->getParameter('deposit_pdf') . '/' . $docId . '.pdf'
        );
        if ($insertRef === false) {
            if (!$this->references->getDocument($docId) instanceof Document) {
                $this->references->createDocumentId($docId);
            }
            $this->addFlash('notice', $this->translator->trans(self::NO_REFERENCE_FOUND_MESSAGE));
        }
        return new JsonResponse(['success' => $insertRef]);
    }

    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId): BinaryFileResponse
    {
        $this->logger->info('get PDF in cache => ', ['path' => $this->getParameter("deposit_pdf") . "/" . $docId . ".pdf"]);
        return new BinaryFileResponse($this->getParameter("deposit_pdf") . "/" . $docId . ".pdf", Response::HTTP_OK)
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $docId . ".pdf");
    }
}
