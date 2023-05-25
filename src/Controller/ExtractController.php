<?php

namespace App\Controller;

use App\Form\DocumentType;
use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ExtractController extends AbstractController
{
    /**
     * @param Grobid $grobid
     * @param References $references
     * @param Episciences $episciences
     */

    public function __construct(private Grobid $grobid,private References $references, private Episciences $episciences)
    {
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param int $docId
     * @return Response
     */

    #[Route('/before-extract/{docId}', name: 'app_before_extract')]

    public function index(EntityManagerInterface $entityManager, int $docId): Response
    {
        return $this->render('extract/beforeextract.html.twig');
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param Request $request
     * @return RedirectResponse
     * @throws \JsonException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    #[Route('/extract', name: 'app_extract')]

    public function extract(EntityManagerInterface $entityManager, Request $request) : RedirectResponse
    {
        $getPdf = $this->episciences->getPaperPDF($request->query->get('url'));
        $docId = $this->episciences->getDocIdFromUrl($request->query->get('url'));
        $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
        return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param int $docId
     * @param Request $request
     * @return Response
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[Route('/{_locale<en|fr>}/viewref/{docId}', name: 'app_view_ref')]

    public function viewReference(EntityManagerInterface $entityManager,int $docId, Request $request) : Response
    {
        $session = $request->getSession();
        $form = $this->createForm(DocumentType::class,$this->references->getDocument($docId));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('submitNewRef')->isClicked()) {
                $newRef = $this->references->addNewReference($request->request->all($form->getName()),$this->container->get('security.token_storage')->getToken()->getAttributes());
                if ($newRef) {
                    $this->addFlash(
                        'success',
                        'New Reference Added'
                    );
                } else {
                    $this->addFlash(
                        'error',
                        'Title missing to add new reference'
                    );
                }
            } elseif ($form->get('save')->isClicked()) {
                $userChoice = $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()),$this->container->get('security.token_storage')->getToken()->getAttributes());
                $this->flashMessageForChoices($userChoice);
            }
            return $this->redirect($request->getUri());
        }
        return $this->render('extract/index.html.twig',[
            'form' => $form->createView(),
            'rvCode' => $session->get('rvCode')
        ]);
    }

    /**
     * @param int $docId
     * @return BinaryFileResponse
     */
    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId): BinaryFileResponse
    {
        return (new BinaryFileResponse($this->getParameter("deposit_pdf")."/".$docId.".pdf", Response::HTTP_OK))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE,$docId.".pdf");
    }

    /**
     * @param array $userChoice
     * @return void
     */
    public function flashMessageForChoices(array $userChoice): void
    {
        if ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                'References and order saved'
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] > 0) {
            $this->addFlash(
                'success',
                'References saved'
            );
        } elseif ($userChoice['orderPersisted'] > 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'success',
                'Order saved'
            );
        } elseif ($userChoice['orderPersisted'] === 0 && $userChoice['referencePersisted'] === 0) {
            $this->addFlash(
                'notice',
                'Nothing change'
            );
        }
    }
}