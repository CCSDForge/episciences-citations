<?php

namespace App\Controller;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Form\PaperReferenceType;
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
    #[Route('/viewref/{docId}', name: 'app_view_ref')]

    public function viewReference(EntityManagerInterface $entityManager,int $docId, Request $request) : Response
    {
        $session = $request->getSession();
        $form = $this->createForm(DocumentType::class,$this->references->getDocument($docId));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()),$this->container->get('security.token_storage')->getToken()->getAttributes());
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
     * @param Request $request
     * @return Response
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \JsonException
     */
    #[Route('/removeref', name: 'app_remove_ref')]
    public function removeRef(Request $request): Response
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (isset($body['idRef'], $body['docId']) && $body['idRef'] !== '' && $body['docId'] !== '') {
            $archiveRef = $this->references->archiveReference($body['docId'],$body['idRef'],$this->container->get('security.token_storage')->getToken()->getAttributes()['UID']);
            if($archiveRef === true) {
                return new Response(json_encode(["status" => Response::HTTP_OK, 'message' => 'Reference removed'], JSON_THROW_ON_ERROR),
                    Response::HTTP_OK,
                    ['content-type' => 'text/json']);
            }
            return new Response(json_encode(["status" => Response::HTTP_BAD_REQUEST, 'message' => 'Reference not found'], JSON_THROW_ON_ERROR),
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'text/json']);
        }
        return new Response(json_encode(["status" => Response::HTTP_BAD_REQUEST, 'message' => 'Reference not found'], JSON_THROW_ON_ERROR),
            Response::HTTP_BAD_REQUEST,
            ['content-type' => 'text/json']);
    }
}