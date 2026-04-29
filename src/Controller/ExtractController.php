<?php

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Services\Bibtex;
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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExtractController extends AbstractController
{

    public function __construct(private readonly Grobid             $grobid,
                                private readonly References         $references,
                                private readonly Episciences        $episciences,
                                private readonly Bibtex             $bibtex,
                                private readonly LoggerInterface    $logger,
                                private readonly TranslatorInterface $translator,
                                private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws JsonException @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface @throws \JsonException
     */
    #[Route('/extract', name: 'app_extract')]
    public function extract(Request $request): RedirectResponse|Response
    {


        $docId = $this->episciences->getDocIdFromUrl($request->query->get('url'));
        $getPdf = $this->episciences->getPaperPDF($request->query->get('url'));

        $this->logger->info('Extracting for docid ', ['DocId' => $docId]);
        $this->logger->info('Extracting for pdf ', ['PDF' => $getPdf]);

        if ($request->query->get('exportbib') === "1") {
            if (!$this->references->getDocument($docId) instanceof Document) {
                $this->references->createDocumentId($docId);
            }
            return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
        }
        if (isset($getPdf['status']) && $getPdf['status'] === 404) {
            $this->logger->error('Unable to get PDF from Episciences ', ['PDF' => $getPdf]);
            throw $this->createNotFoundException('Unable to get PDF from Episciences');

        }
        $session = $request->getSession();
        $session->set('openModalClose', 0);
        if ($this->references->documentAlreadyExtracted($docId) && $request->query->has('rextract')) {
            $this->logger->info('Rextract => ', ['Rextract - DocId' => $docId]);
            if (!$this->grobid->hasCachedReferences($docId)) {
                return $this->renderProcessingPage($docId, $request);
            }
            $insertRef = $this->grobid->insertReferences($docId, $this->getParameter("deposit_pdf") . "/" . $docId . ".pdf");
            if ($insertRef === false) {
                $this->addFlash(
                    'notice',
                    $this->translator->trans('No references found in the PDF')
                );
            }
            return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
        }

        if ($this->references->documentAlreadyExtracted($docId)) {
            if ($this->references->getReferences($docId, 'all') === []) {
                // Refs absent — attempt (re)insertion: uses cache if available, calls GROBID otherwise
                $this->logger->info('Document exists with no refs — retrying extraction', ['DocId' => $docId]);
                if (!$this->grobid->hasCachedReferences($docId)) {
                    return $this->renderProcessingPage($docId, $request);
                }
                $insertRef = $this->grobid->insertReferences($docId, $this->getParameter('deposit_pdf') . '/' . $docId . '.pdf');
                if ($insertRef === false) {
                    $this->addFlash('notice', $this->translator->trans('No reference found in the PDF'));
                }
            }
            $this->logger->info('Get in database document refs already extracted ', ['DocId' => $docId]);
            return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
        }

        $this->logger->info('Insert references for the first time ', ['DocId' => $docId]);
        if (!$this->grobid->hasCachedReferences($docId)) {
            return $this->renderProcessingPage($docId, $request);
        }
        $insertRef = $this->grobid->insertReferences($docId, $this->getParameter("deposit_pdf") . "/" . $docId . ".pdf");
        if ($insertRef === false) {
            $this->addFlash(
                'notice',
                $this->translator->trans('No reference found in the PDF')
            );
            if (!$this->references->getDocument($docId) instanceof Document) {
                $this->references->createDocumentId($docId);
            }
        }
        return $this->redirectToRoute('app_view_ref', ['docId' => $docId]);
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
    public function viewReference(int                 $docId, Request $request): Response
    {
        $this->logger->info('view ref page', ['docId' => $docId,
            'attribute cas' => $this->container->get('security.token_storage')->getToken()->getAttributes()]);
        if ($this->isAuthorizeForApp($docId)) {
            $session = $request->getSession();
            $form = $this->createForm(DocumentType::class, $this->references->getDocument($docId));
            $form->handleRequest($request);

            $errors = $this->validator->validate($form);
            if (count($errors) > 0) {
                foreach ($errors as $violation) {
                    $this->addFlash(
                        'error',
                        $this->translator->trans($violation->getMessage())
                    );
                }
            }
            if ($form->isSubmitted() && $form->isValid()) {
                $session->set('openModalClose', 0);
                if ($form->get('submitNewRef')->isClicked()) {
                    $newRef = $this->references->addNewReference($request->request->all($form->getName()),
                        $this->container->get('security.token_storage')->getToken()->getAttributes());
                    $this->logger->info('New reference added');
                    if ($newRef) {
                        $this->addFlash(
                            'success',
                            $this->translator->trans('New Reference Added')
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            $this->translator->trans('Title missing to add new reference')
                        );
                    }
                } elseif ($form->get('save')->isClicked()) {
                    $userChoice = $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()),
                        $this->container->get('security.token_storage')->getToken()->getAttributes());
                    $this->flashMessageForChoices($userChoice);
                } elseif ($form->get('submitImportBib')->isClicked()) {
                    $bibtexFile = $form->get('bibtexFile')->getData();
                    if ($bibtexFile !== null) {
                        $process = $this->bibtex->processBibtex($bibtexFile,
                            $this->container->get('security.token_storage')->getToken()->getAttributes(), $docId);
                        if ($process !== []) {
                            $this->addFlash(
                                'error',
                                $this->translator->trans($process['error'])
                            );
                        }
                    } else {
                        $this->addFlash(
                            'error',
                            $this->translator->trans('Please add a BibTeX file')
                        );
                    }
                }
                $session->set('openModalClose', 0);
                if ($session->get('isAlreadyopenModal') === 0) {
                    $session->set('openModalClose', 1);
                    $session->set('isAlreadyopenModal', 1);
                }
                return $this->redirect($request->getUri());
            }
            return $this->render('extract/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }
        $this->logger->warning('Access Denied for this user : ',
            [
                'DOCID' => $docId,
                'USER CAS' => $this->container->get('security.token_storage')->getToken()->getAttributes()
            ]);
        throw $this->createAccessDeniedException();
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
        return $this->episciences->getRightUser($docId,
            $this->container->get('security.token_storage')->getToken()->getAttributes()['UID']);
    }

    /**
     * @param TranslatorInterface $translator
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
        if (!$this->isCsrfTokenValid('autosave', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false], 403);
        }
        if (!$this->isAuthorizeForApp($docId)) {
            return new JsonResponse(['success' => false], 403);
        }

        $data = $request->request->all();
        $userInfo = $this->container->get('security.token_storage')->getToken()->getAttributes();

        if (isset($data['orderRef'])) {
            $this->references->autosaveOrder($data['orderRef']);
        } elseif (isset($data['refId'])) {
            $this->references->autosaveReference(
                (int) $data['refId'],
                $data['reference'] ?? '{}',
                (int) ($data['accepted'] ?? 0),
                ($data['isDirty'] ?? '0') === '1',
                $userInfo
            );
        }

        return new JsonResponse(['success' => true]);
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
            $this->addFlash('notice', $this->translator->trans('No reference found in the PDF'));
        }
        return new JsonResponse(['success' => $insertRef !== false]);
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
        if (!$this->isValidApiToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $url = (string) $request->query->get('url', '');
        if ($url === '') {
            return new JsonResponse(['success' => false, 'error' => 'Missing required parameter: url'], Response::HTTP_BAD_REQUEST);
        }

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

        $referenceCount = $this->grobid->countAllReferencesFromDB($docId);
        if ($referenceCount > 0) {
            return new JsonResponse(['success' => true, 'docId' => $docId, 'alreadyExtracted' => true, 'referenceCount' => $referenceCount]);
        }

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
        if ($expected === '') {
            return true;
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

    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId): BinaryFileResponse
    {
        $this->logger->info('get PDF in cache => ', ['path' => $this->getParameter("deposit_pdf") . "/" . $docId . ".pdf"]);
        return (new BinaryFileResponse($this->getParameter("deposit_pdf") . "/" . $docId . ".pdf", Response::HTTP_OK))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $docId . ".pdf");
    }
}