<?php

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExtractController extends AbstractController
{
    private const string NO_REFERENCE_FOUND_MESSAGE = 'No reference found in the PDF';

    public function __construct(private readonly Grobid                   $grobid,
                                private readonly References               $references,
                                private readonly Episciences              $episciences,
                                private readonly Bibtex                   $bibtex,
                                private readonly Doi                      $doiService,
                                private readonly LoggerInterface          $logger,
                                private readonly TranslatorInterface      $translator,
                                private readonly ValidatorInterface       $validator,
                                private readonly SemanticScholarImporter  $semanticsScholarImporter)
    {
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

    /**
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws JsonException
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    #[Route('/{_locale<en|fr>}/viewref/{docId}', name: 'app_view_ref')]
    #[IsGranted('ROLE_USER')]
    public function viewReference(int $docId, Request $request): Response
    {
        $this->logger->info('view ref page', ['docId' => $docId, 'attribute cas' => $this->getUserAttributes()]);

        if (!$this->isAuthorizeForApp($docId)) {
            $this->logger->warning('Access Denied for this user : ',
                ['DOCID' => $docId, 'USER CAS' => $this->getUserAttributes()]);
            throw $this->createAccessDeniedException();
        }

        if (!$this->references->getDocument($docId) instanceof Document) {
            $this->logger->info('Document not yet extracted, creating stub', ['docId' => $docId]);
            $this->references->createDocumentId($docId);
        }

        $form = $this->createForm(DocumentType::class, $this->references->getDocument($docId));
        $form->handleRequest($request);

        foreach ($this->validator->validate($form) as $violation) {
            $this->addFlash('error', $this->translator->trans($violation->getMessage()));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->handleValidFormSubmission($form, $docId, $request);
        }

        if ($form->isSubmitted()) {
            $this->logger->warning('Form is invalid', [
                'docId' => $docId,
                'locale' => $request->getLocale(),
                'errors' => (string) $form->getErrors(true, false)
            ]);
            $this->addFlash('error', $this->translator->trans('Invalid data submitted'));
        }

        return $this->render('extract/index.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @param FormInterface<Document> $form
     */
    private function handleValidFormSubmission(FormInterface $form, int $docId, Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $session->set('openModalClose', 0);

        $userInfo = $this->getUserAttributes();
        $submitNewRef = $form->get('submitNewRef');
        $submitSave = $form->get('save');
        $submitImportBib = $form->get('submitImportBib');

        if ($submitNewRef instanceof ClickableInterface && $submitNewRef->isClicked()) {
            $this->handleNewReferenceSubmit($form, $request, $userInfo);
        } elseif ($submitSave instanceof ClickableInterface && $submitSave->isClicked()) {
            $this->handleSaveSubmit($form, $request, $userInfo);
        } elseif ($submitImportBib instanceof ClickableInterface && $submitImportBib->isClicked()) {
            $this->handleImportBibSubmit($form, $docId, $userInfo);
        }

        $session->set('openModalClose', 0);
        if ($session->get('isAlreadyopenModal') === 0) {
            $session->set('openModalClose', 1);
            $session->set('isAlreadyopenModal', 1);
        }

        return $this->redirect($request->getUri());
    }

    /**
     * @param FormInterface<Document> $form
     * @param array<string, mixed> $userInfo
     */
    private function handleNewReferenceSubmit(FormInterface $form, Request $request, array $userInfo): void
    {
        $newRef = $this->references->addNewReference($request->request->all($form->getName()), $userInfo);
        $this->logger->info('New reference added');
        if ($newRef) {
            $this->addFlash('success', $this->translator->trans('New Reference Added'));
        } else {
            $this->addFlash('error', $this->translator->trans('Title missing to add new reference'));
        }
    }

    /**
     * @param FormInterface<Document> $form
     * @param array<string, mixed> $userInfo
     */
    private function handleSaveSubmit(FormInterface $form, Request $request, array $userInfo): void
    {
        $this->logger->info('Manual save triggered', ['locale' => $request->getLocale()]);
        $userChoice = $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()), $userInfo);
        $this->logger->info('Manual save result', $userChoice);
        $this->flashMessageForChoices($userChoice);
    }

    /**
     * @param FormInterface<Document> $form
     * @param array<string, mixed> $userInfo
     */
    private function handleImportBibSubmit(FormInterface $form, int $docId, array $userInfo): void
    {
        $bibtexFile = $form->get('bibtexFile')->getData();
        if ($bibtexFile === null) {
            $this->addFlash('error', $this->translator->trans('Please add a BibTeX file'));
            return;
        }

        $process = $this->bibtex->processBibtex($bibtexFile, $userInfo, $docId);
        if ($process !== []) {
            $this->addFlash('error', $this->translator->trans($process['error']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserAttributes(): array
    {
        return $this->container->get('security.token_storage')->getToken()->getAttributes();
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function isAuthorizeForApp(int $docId): bool
    {
        return $this->episciences->getRightUser((string) $docId,
            $this->container->get('security.token_storage')->getToken()->getAttributes()['UID']);
    }

    /**
     * @param array<string, int> $userChoice
     */
    public function flashMessageForChoices(array $userChoice): void
    {
        if ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                $this->translator->trans('The references and sorting have been saved')
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                $this->translator->trans('The references have been saved')
            );
        } elseif ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'success',
                $this->translator->trans('The sorting has been saved')
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'notice',
                $this->translator->trans('Nothing was changed')
            );
        }
    }

    #[Route('/{_locale<en|fr>}/viewref/{docId}/autosave', name: 'app_autosave', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function autosave(int $docId, Request $request): JsonResponse
    {
        $authError = $this->validateAutosaveRequest($docId, $request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->processAutosaveData($docId, $request);
    }

    private function validateAutosaveRequest(int $docId, Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('autosave', $request->request->get('_token'))) {
            $this->logger->warning('Autosave: Invalid CSRF token');
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->isAuthorizeForApp($docId)) {
            $this->logger->warning('Autosave: Access Denied', ['docId' => $docId]);
            return new JsonResponse(['success' => false, 'error' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function processAutosaveData(int $docId, Request $request): JsonResponse
    {
        $data = $request->request->all();
        $this->logger->info('Autosave triggered', ['docId' => $docId, 'data' => array_intersect_key($data, array_flip(['refId', 'accepted', 'isDirty', 'orderRef']))]);

        if (isset($data['orderRef'])) {
            $this->references->autosaveOrder($data['orderRef']);
            return new JsonResponse(['success' => true]);
        }

        if (isset($data['refId'])) {
            $userInfo = $this->container->get('security.token_storage')->getToken()->getAttributes();
            $enrichedReference = $this->references->autosaveReference(
                (int) $data['refId'],
                $data['reference'] ?? '{}',
                (int) ($data['accepted'] ?? 0),
                ($data['isDirty'] ?? '0') === '1',
                $userInfo
            );
            return new JsonResponse(['success' => true, 'reference' => $enrichedReference]);
        }

        return new JsonResponse(['success' => false, 'error' => 'No data to save']);
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

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid URL: only http and https are allowed'],
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
        return $request->headers->get('Authorization') === 'Bearer ' . $expected;
    }

    private function renderProcessingPage(int $docId, Request $request): Response
    {
        return $this->render('extract/processing.html.twig', [
            'extractRunUrl' => $this->generateUrl('app_extract_run', ['docId' => $docId]),
            'viewRefUrl'    => $this->generateUrl('app_view_ref', ['docId' => $docId, '_locale' => $request->getLocale()]),
        ]);
    }

    #[Route('/{_locale<en|fr>}/viewref/{docId}/import-semantic-scholar', name: 'app_import_semantic_scholar', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function importFromSemanticScholar(int $docId, Request $request): JsonResponse
    {
        $authError = $this->validateSemanticScholarRequest($docId, $request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->performSemanticScholarImport($docId, $request);
    }

    private function validateSemanticScholarRequest(int $docId, Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('import-semantic-scholar', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }
        if (!$this->isAuthorizeForApp($docId)) {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function performSemanticScholarImport(int $docId, Request $request): JsonResponse
    {
        $paperId = trim((string) $request->request->get('paperId', ''));
        if ($paperId === '') {
            return new JsonResponse(
                ['success' => false, 'error' => $this->translator->trans('Please enter a paper ID.')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $startOrder = count($this->references->getReferences($docId, 'all'));

        try {
            $count = $this->semanticsScholarImporter->importByPaperId($paperId, $docId, $startOrder);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }

        return new JsonResponse([
            'success' => true,
            'count'   => $count,
            'message' => $this->translator->trans('%count% reference(s) imported from Semantic Scholar', ['%count%' => $count]),
        ]);
    }

    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId): BinaryFileResponse
    {
        $this->logger->info('get PDF in cache => ', ['path' => $this->getParameter("deposit_pdf") . "/" . $docId . ".pdf"]);
        return new BinaryFileResponse($this->getParameter("deposit_pdf") . "/" . $docId . ".pdf", Response::HTTP_OK)
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $docId . ".pdf");
    }

    #[Route('/doi/enrich', name: 'app_doi_enrich', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function enrichFromDoi(Request $request): JsonResponse
    {
        $doi = trim((string) $request->query->get('doi', ''));
        if ($doi === '') {
            return new JsonResponse(['success' => false, 'error' => 'DOI is required'], Response::HTTP_BAD_REQUEST);
        }

        $citation = $this->doiService->getFormattedCitation($doi);
        $cslJson = $this->doiService->getCsl($doi);

        if ($citation === '' && $cslJson === '') {
            return new JsonResponse(['success' => false, 'error' => 'Could not fetch data for this DOI'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success'  => true,
            'citation' => $citation,
            'csl'      => json_decode($cslJson, true)
        ]);
    }
}
