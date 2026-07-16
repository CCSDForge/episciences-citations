<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\Episciences;
use App\Services\References;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the reference-editing UI: the viewref page, saving/autosaving user
 * choices, adding a reference by hand, importing a BibTeX file, and the
 * DOI-enrichment lookup used from that page.
 *
 * Split out of the former monolithic ExtractController to stay under Sonar's
 * 20-method-per-class limit (S1448); see ExtractController, ApiExtractController
 * and SemanticScholarImportController for the other extraction-related actions.
 */
class ReferenceEditController extends AbstractController
{
    use DocumentAccessTrait;

    public function __construct(
        private readonly References $references,
        private readonly Bibtex $bibtex,
        private readonly Doi $doiService,
        private readonly Episciences $episciences,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly ValidatorInterface $validator,
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
    #[Route('/{_locale<en|fr>}/viewref/{docId}', name: 'app_view_ref')]
    #[IsGranted('ROLE_USER')]
    public function viewReference(int $docId, Request $request): Response
    {
        $this->logger->info('view ref page', ['docId' => $docId, 'attribute cas' => $this->getUserAttributes()]);

        if (!$this->isAuthorizeForApp($this->episciences, $docId)) {
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
     * @param array<string, int> $userChoice
     */
    private function flashMessageForChoices(array $userChoice): void
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
        if (!$this->isAuthorizeForApp($this->episciences, $docId)) {
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
            $userInfo = $this->getUserAttributes();
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
