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
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class EpisciencesController extends AbstractController {


    public function __construct(private Episciences $episciences, private Grobid $grobid,private References $references)
    {
    }

    /**
     * @param string $rvCode
     * @param int $docId
     * @return Response
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \JsonException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */

    #[Route('/get-citations/{rvCode}/{docId}', name: 'app_epi_service_get_references')]
    public function getCitationEpisciences(string $rvCode,int $docId): Response
    {
        $getPdf = $this->episciences->getPaperPDF($rvCode, $docId);
        if ($getPdf === true) {
            $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
        }
        if (is_array($getPdf)) {
            return new Response(
                json_encode($getPdf, JSON_THROW_ON_ERROR),
                $getPdf['status'],
                ['content-type' => 'text/json']
            );
        }
        return new Response(
            $this->references->getReferences($docId,"json"),
            Response::HTTP_OK,
            ['content-type' => 'text/json']
        );
    }
}