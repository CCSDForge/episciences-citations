<?php

namespace App\Controller;

use App\Form\DocumentType;
use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use App\Services\Bibtex;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExtractController extends AbstractController
{

    public function __construct(private Grobid $grobid,
                                private References $references,
                                private Episciences $episciences,
                                private Bibtex $bibtex,
                                private LoggerInterface $logger,
                                private ValidatorInterface $validator)
    {
    }

    /**
     * @param Request $request
     * @return RedirectResponse|Response
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface @throws \JsonException
     */
    #[Route('/extract', name: 'app_extract')]

    public function extract(Request $request,TranslatorInterface $translator) : RedirectResponse | Response
    {

        $docId = $this->episciences->getDocIdFromUrl($request->query->get('url'));
        $getPdf = $this->episciences->getPaperPDF($request->query->get('url'));
        if ($request->query->get('exportbib') === "1") {
            if ($this->references->getDocument($docId) === null){
                $this->references->createDocumentId($docId);
            }
            return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
        }
        if (isset($getPdf['status']) && $getPdf['status'] === 404) {
            throw $this->createNotFoundException('Unable to get PDF from Episciences');
        }
        $session = $request->getSession();
        $session->set('openModalClose', 0);
        if ($this->references->documentAlreadyExtracted($docId) && $request->query->has('rextract')){
            $this->logger->info('Rextract => ', ['Rextract - DocId' => $docId]);
            $insertRef = $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
            if ($insertRef === false){
                $this->addFlash(
                    'notice',
                    $translator->trans('No reference found in the PDF')
                );
            }
            return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
        }

        if ($this->references->documentAlreadyExtracted($docId)) {
            if (empty($this->references->getReferences($docId,'all'))){
                $this->addFlash(
                    'notice',
                    $translator->trans('No reference found in the PDF')
                );
            }
            $this->logger->info('Get in database document refs already extracted ', ['DocId' => $docId]);
            return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
        }
        $this->logger->info('Insert references for the first time ', ['DocId' => $docId]);
        $insertRef = $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
        if ($insertRef === false){
            $this->addFlash(
                'notice',
                $translator->trans('No reference found in the PDF')
            );
            if ($this->references->getDocument($docId) === null) {
                $this->references->createDocumentId($docId);
            }
        }
        return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
    }

    /**
     * @param int $docId
     * @param Request $request
     * @param TranslatorInterface $translator
     * @param LoggerInterface $logger
     * @return Response
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \JsonException
     */
    #[Route('/{_locale<en|fr>}/viewref/{docId}', name: 'app_view_ref')]
    #[IsGranted('ROLE_USER')]

    public function viewReference(int $docId, Request $request,
                                  TranslatorInterface $translator,
                                  LoggerInterface $logger,
                                  ValidatorInterface $validator) : Response
    {
        $logger->info('view ref page',['docId' => $docId,
            'attribute cas'=> $this->container->get('security.token_storage')->getToken()->getAttributes()]);
        if ($this->isAuthorizeForApp($docId) === true) {
            $session = $request->getSession();
            $form = $this->createForm(DocumentType::class, $this->references->getDocument($docId));
            $form->handleRequest($request);

            $errors  = $validator->validate($form);
            if (count($errors) > 0) {
                foreach ($errors as $violation) {
                    $this->addFlash(
                        'error',
                        $translator->trans($violation->getMessage())
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
                            $translator->trans('New Reference Added')
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            $translator->trans('Title missing to add new reference')
                        );
                    }
                } elseif ($form->get('save')->isClicked()) {
                    $userChoice = $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()),
                        $this->container->get('security.token_storage')->getToken()->getAttributes());
                    $this->flashMessageForChoices($userChoice, $translator);
                } elseif ($form->get('submitImportBib')->isClicked()){
                    $bibtexFile = $form->get('bibtexFile')->getData();
                    $process = $this->bibtex->processBibtex($bibtexFile,
                        $this->container->get('security.token_storage')->getToken()->getAttributes(),$docId);
                    if (!empty($process)){
                        $this->addFlash(
                            'error',
                            $translator->trans($process['error'])
                        );
                    }
                }
                $session->set('openModalClose', 1);
                return $this->redirect($request->getUri());
            }
            return $this->render('extract/index.html.twig', [
                'form' => $form->createView(),
            ]);
        } else {
            $logger->warning('Access Denied for this user : ',
                [
                    'DOCID' => $docId,
                    'USER CAS' => $this->container->get('security.token_storage')->getToken()->getAttributes()
                ]);
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * @param int $docId
     * @return BinaryFileResponse
     */
    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId): BinaryFileResponse
    {
        $this->logger->info('get PDF in cache => ',['path' => $this->getParameter("deposit_pdf")."/".$docId.".pdf"]);
        return (new BinaryFileResponse($this->getParameter("deposit_pdf")."/".$docId.".pdf", Response::HTTP_OK))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE,$docId.".pdf");
    }

    /**
     * @param array $userChoice
     * @param TranslatorInterface $translator
     * @return void
     */
    public function flashMessageForChoices(array $userChoice, TranslatorInterface $translator): void
    {
        if ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                $translator->trans('References and sorting saved')
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                $translator->trans('References saved')
            );
        } elseif ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'success',
                $translator->trans('Order saved')
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'notice',
                $translator->trans('Nothing change')
            );
        }
    }

    /**
     * @param int $docId
     * @return bool
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
}