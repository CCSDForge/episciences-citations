<?php

namespace App\Controller;

use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EpisciencesController extends AbstractController
{
    const APPLICATION_JSON = 'application/json';

    /**
     * @param Episciences $episciences
     * @param Grobid $grobid
     * @param References $references
     * @param LoggerInterface $logger
     */
    public function __construct(private Episciences $episciences, private Grobid $grobid, private References $references, private LoggerInterface $logger)
    {
    }

    /**
     * @throws \JsonException
     */
    #[Route('/visualize-citations', name: 'app_epi_service_visualize_citations')]
    public function visualizeCitations(Request $request): Response
    {
        if ($this->checkCors($request) === true) {
            header('Access-Control-Allow-Origin: ' . $request->headers->get('origin'));
            if (is_null($request->get('url')) || $request->get('url') === '') {
                $this->logger->warning('API called with bad URL or NULL', ['url', $request->get('url')]);
                return new Response(
                    json_encode([
                        "status" => Response::HTTP_BAD_REQUEST,
                        "message" => 'An URL is missing'],
                        JSON_THROW_ON_ERROR),
                    Response::HTTP_BAD_REQUEST,
                    ['content-type' => self::APPLICATION_JSON]
                );

            }
            $docId = $this->episciences->getDocIdFromUrl($request->get('url'));
            $this->logger->info('API called for docid: ', [$docId]);
            if ($docId === "") {
                return new Response(
                    json_encode([
                        "status" => Response::HTTP_BAD_REQUEST,
                        "message" => 'A docid is missing'],
                        JSON_THROW_ON_ERROR),
                    Response::HTTP_BAD_REQUEST,
                    ['content-type' => self::APPLICATION_JSON]
                );
            }
            if ($request->query->has('all') && $request->query->get('all') === "1") {
                $this->logger->info('API called for all references with docid ', [$docId]);
                $refs = $this->references->getReferences($docId, 'all');
            } else {
                $this->logger->info('API called for accepted references and docid ', [$docId]);
                $refs = $this->references->getReferences($docId, 'accepted');
            }

            if (empty($refs)) {
                $this->logger->info('API called But no references were found for: ', [$docId]);
                return new Response(
                    json_encode(["status" => Response::HTTP_OK,
                        "message" => 'No reference found'], JSON_THROW_ON_ERROR),
                    Response::HTTP_OK,
                    ['content-type' => self::APPLICATION_JSON]
                );
            }
            return new Response(
                json_encode($refs, JSON_THROW_ON_ERROR),
                Response::HTTP_OK,
                ['content-type' => self::APPLICATION_JSON]
            );
        }
        $this->logger->alert('FORBIDDEN CORS origin');
        return new Response(
            json_encode(["status" => Response::HTTP_FORBIDDEN,
                "message" => 'Forbidden'], JSON_THROW_ON_ERROR),
            Response::HTTP_FORBIDDEN,
            ['content-type' => self::APPLICATION_JSON]
        );
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function checkCors(Request $request): bool
    {
        $this->logger->info('Checking CORS for this URL: ', [
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