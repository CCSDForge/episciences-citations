<?php
namespace App\Controller;

use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class EpisciencesController extends AbstractController {


    public function __construct(private Episciences $episciences, private Grobid $grobid,private References $references)
    {
    }

    /**
     * @param string rvCode
     * @param int $docId
     * @return Response
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */

    #[Route('/get-citations/{rvCode}/{docId}', name: 'app_epi_service_get_references')]
    public function getCitationEpisciences(string $rvCode,int $docId)
    {
        $getPdf = $this->episciences->getPaperPDF($rvCode, $docId);
        if ($getPdf === true){
            $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
        }
        return new Response(
            $this->references->getReferences($docId,"json"),
            Response::HTTP_OK,
            ['content-type' => 'text/json']
        );
    }
}