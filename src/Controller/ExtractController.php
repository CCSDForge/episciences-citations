<?php

namespace App\Controller;

use App\Entity\PaperReferences;
use App\Form\ReferencesFormType;
use App\Services\Grobid;
use App\Services\References;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class ExtractController extends AbstractController
{
    /**
     * @param Grobid $grobid
     * @param References $references
     */

    public function __construct(private Grobid $grobid,private References $references)
    {
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param int $docId
     * @return Response
     */

    #[Route('/before-extract/{docId}', name: 'app_before_extract')]

    public function index(EntityManagerInterface $entityManager, int $docId) {
        return $this->render('extract/beforeextract.html.twig');
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param int $docId
     * @param Request $request
     */
    #[Route('/extract/{docId}', name: 'app_extract')]

    public function extract(EntityManagerInterface $entityManager,int $docId, Request $request) : RedirectResponse
    {
        $this->grobid->insertReferences($this->getParameter("deposit_pdf")."/6816.pdf");
        return $this->redirectToRoute('app_view_ref',['docId'=> $docId]);
    }

    #[Route('/viewref/{docId}', name: 'app_view_ref')]

    public function viewReference(EntityManagerInterface $entityManager,int $docId, Request $request) : Response {
        $references = $this->grobid->getGrobidReferencesFromDB(6816);
        $rawReferences = [];
        foreach ($references as $reference) {
            /** @var PaperReferences $reference */
            foreach ($reference->getReference() as $allReferences) {
                $rawReferences['ref'][$reference->getId()] = json_decode($allReferences, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $form = $this->createForm(ReferencesFormType::class, $references);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->references->validateChoicesReferencesByUser($request->request->all($form->getName()));
        }
        return $this->render('extract/index.html.twig',[
            'form' => $form->createView(),
            'references' => $rawReferences
        ]);
    }

    /**
     * @param int $docId
     * @return BinaryFileResponse
     */
    #[Route('/getpdf/{docId}', name: 'app_get_pdf')]
    public function getpdf(int $docId) {
        return (new BinaryFileResponse($this->getParameter("deposit_pdf")."/6816.pdf", Response::HTTP_OK))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE,"6816.pdf");
    }

}