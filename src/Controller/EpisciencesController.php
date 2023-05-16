<?php
namespace App\Controller;

use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
     * @param Request $request
     * @return Response
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \JsonException
     */

    #[Route('/process-citations', name: 'app_epi_service_get_references')]
    public function processReferencesEpisciences(Request $request): Response
    {
        if(is_null($request->get('url')) || $request->get('url') === '') {
            return new Response(
                json_encode([
                    "status"=> Response::HTTP_BAD_REQUEST,
                    "message"=> 'Something is missing'],
                    JSON_THROW_ON_ERROR),
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'text/json']
            );

        }
        $getPdf = $this->episciences->getPaperPDF($request->get('url'));
        $docId = $this->episciences->getDocIdFromUrl($request->get('url'));
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
            json_encode($this->references->getReferences($docId,'all'), JSON_THROW_ON_ERROR),
            Response::HTTP_OK,
            ['content-type' => 'text/json']
        );
    }

    /**
     * @throws \JsonException
     */
    #[Route('/visualize-citations', name: 'app_epi_service_visualize_citations')]
    public function visualizeCitations(Request $request): Response {
        if(is_null($request->get('url')) || $request->get('url') === '') {
            return new Response(
                json_encode([
                    "status"=> Response::HTTP_BAD_REQUEST,
                    "message"=> 'Something is missing'],
                    JSON_THROW_ON_ERROR),
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'text/json']
            );

        }
        $docId = $this->episciences->getDocIdFromUrl($request->get('url'));
        $refs = $this->references->getReferences($docId,'accepted');
        return new Response(
            json_encode($refs,JSON_THROW_ON_ERROR),
            Response::HTTP_OK,
            ['content-type' => 'text/json']
        );
    }
}