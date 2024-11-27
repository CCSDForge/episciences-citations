<?php
namespace App\Controller;

use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
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

    /**
     * @param Episciences $episciences
     * @param Grobid $grobid
     * @param References $references
     * @param LoggerInterface $logger
     */
    public function __construct(private Episciences $episciences, private Grobid $grobid,private References $references, private LoggerInterface $logger)
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
                $this->logger->warning('Api called with bad URL or NULL',['url',$request->get('url')]);
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
            $this->logger->info('Api called FOR ',[$docId]);
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
                $this->logger->info('Api called with all references ',[$docId]);
                $refs = $this->references->getReferences($docId,'all');
            } else {
                $this->logger->info('Api called just accepted ref ',[$docId]);
                $refs = $this->references->getReferences($docId,'accepted');
            }

            if(empty($refs)) {
                $this->logger->info('Api called But no ref bib found for: ',[$docId]);
                return new Response(
                    json_encode(["status"=> Response::HTTP_OK,
                        "message"=> 'No reference found'],JSON_THROW_ON_ERROR),
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
        $this->logger->alert('FORBIDDEN CORS origin');
        return new Response(
            json_encode(["status"=> Response::HTTP_FORBIDDEN,
                "message"=> 'Forbidden'],JSON_THROW_ON_ERROR),
            Response::HTTP_FORBIDDEN,
            ['content-type' => 'application/json']
        );
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function checkCors(Request $request): bool
    {
        $this->logger->info('check CORS for this url : ', [
            'ORIGIN' => $request->headers->get('origin'),
            'HOST' => $request->headers->get('host')
        ]);

        // Ensure the headers are strings
        $origin = $request->headers->get('origin') ?? '';
        $host = $request->headers->get('host') ?? '';

        // Perform preg_match with safe inputs
        preg_match('/(' . preg_quote($this->getParameter("cors_site"), '/') . ')$/', $origin, $matchesOrigin);
        preg_match('/(' . preg_quote($this->getParameter("cors_site"), '/') . ')$/', $host, $matchesHost);

        if (!empty($matchesOrigin) || !empty($matchesHost)) {
            return true;
        }

        return false;
    }

}