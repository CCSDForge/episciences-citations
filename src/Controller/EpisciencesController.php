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

//    /**
//     * @param Request $request
//     * @return Response
//     * @throws ClientExceptionInterface
//     * @throws ContainerExceptionInterface
//     * @throws NotFoundExceptionInterface
//     * @throws RedirectionExceptionInterface
//     * @throws ServerExceptionInterface
//     * @throws TransportExceptionInterface
//     * @throws \JsonException
//     */

//    #[Route('/process-citations', name: 'app_epi_service_get_references')]
//    public function processReferencesEpisciences(Request $request): Response
//    {
//
//        if ($this->checkCors($request) === true) {
//            header('Access-Control-Allow-Origin: '.$request->headers->get('origin'));
//            if(is_null($request->get('url')) || $request->get('url') === '') {
//                return new Response(
//                    json_encode([
//                        "status"=> Response::HTTP_BAD_REQUEST,
//                        "message"=> 'Something is missing'],
//                        JSON_THROW_ON_ERROR),
//                    Response::HTTP_BAD_REQUEST,
//                    ['content-type' => 'application/json']
//                );
//            }
//            $getPdf = $this->episciences->getPaperPDF($request->get('url'));
//            $docId = $this->episciences->getDocIdFromUrl($request->get('url'));
//            if ($getPdf === true) {
//                $this->grobid->insertReferences($docId,$this->getParameter("deposit_pdf")."/".$docId.".pdf");
//            }
//            if (is_array($getPdf)) {
//                return new Response(
//                    json_encode($getPdf, JSON_THROW_ON_ERROR),
//                    $getPdf['status'],
//                    ['content-type' => 'application/json']
//                );
//            }
//            return new Response(
//                json_encode($this->references->getReferences($docId,'all'), JSON_THROW_ON_ERROR),
//                Response::HTTP_OK,
//                ['content-type' => 'application/json']
//            );
//        }
//        return new Response(
//            json_encode(["status"=> Response::HTTP_FORBIDDEN,
//                "message"=> 'Forbidden'],JSON_THROW_ON_ERROR),
//            Response::HTTP_FORBIDDEN,
//            ['content-type' => 'application/json']
//        );
//    }

    /**
     * @throws \JsonException
     */
    #[Route('/visualize-citations', name: 'app_epi_service_visualize_citations')]
    public function visualizeCitations(Request $request): Response {
        if ($this->checkCors($request) === true) {
            header('Access-Control-Allow-Origin: '.$request->headers->get('origin'));
            if(is_null($request->get('url')) || $request->get('url') === '') {
                return new Response(
                    json_encode([
                        "status"=> Response::HTTP_BAD_REQUEST,
                        "message"=> 'Something is missing'],
                        JSON_THROW_ON_ERROR),
                    Response::HTTP_BAD_REQUEST,
                    ['content-type' => 'application/json']
                );

            }
            $docId = $this->episciences->getDocIdFromUrl($request->get('url'));
            if ($docId === "") {
                return new Response(
                    json_encode([
                        "status"=> Response::HTTP_BAD_REQUEST,
                        "message"=> 'Something is missing'],
                        JSON_THROW_ON_ERROR),
                    Response::HTTP_BAD_REQUEST,
                    ['content-type' => 'application/json']
                );
            }
            if ($request->query->has('all') && $request->query->get('all') === "1"){
                $refs = $this->references->getReferences($docId,'all');
            } else {
                $refs = $this->references->getReferences($docId,'accepted');
            }

            if(empty($refs)) {
                return new Response(
                    json_encode(["status"=> Response::HTTP_OK,
                        "message"=> 'No References Found'],JSON_THROW_ON_ERROR),
                    Response::HTTP_OK,
                    ['content-type' => 'application/json']
                );
            }
            return new Response(
                json_encode($refs,JSON_THROW_ON_ERROR),
                Response::HTTP_OK,
                ['content-type' => 'application/json']
            );
        }
        return new Response(
            json_encode(["status"=> Response::HTTP_FORBIDDEN,
                "message"=> 'Forbidden'],JSON_THROW_ON_ERROR),
            Response::HTTP_FORBIDDEN,
            ['content-type' => 'application/json']
        );
    }

    /**
     * @param Request $request
     * @param $matchesOrigin
     * @param $matchesHost
     * @return array
     */
    public function checkCors(Request $request): bool
    {
        preg_match('/(' . $this->getParameter("cors_site") . ')$/', $request->headers->get('origin'), $matchesOrigin);
        preg_match('/(' . $this->getParameter("cors_site") . ')$/', $request->headers->get('host'), $matchesHost);
        if (!empty($matchesOrigin) || !empty($matchesHost)) {
            return true;
        }
        return false;
    }
}