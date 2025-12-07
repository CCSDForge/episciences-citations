<?php

namespace App\Controller;

use App\Services\Episciences;
use App\Services\Grobid;
use App\Services\References;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EpisciencesController extends AbstractController
{
    public function __construct(private Episciences $episciences, private Grobid $grobid, private References $references, private LoggerInterface $logger)
    {
    }

    /**
     * @throws \JsonException
     */
    #[Route('/visualize-citations', name: 'app_epi_service_visualize_citations')]
    public function visualizeCitations(Request $request): JsonResponse
    {
        // La validation CORS est maintenant gérée par CorsEventListener
        // Validation URL (early return pattern pour réduire l'imbrication)
        $url = $request->get('url');
        if (empty($url)) {
            $this->logger->warning('API called with bad URL or NULL', ['url' => $url]);
            return new JsonResponse(
                ['status' => Response::HTTP_BAD_REQUEST, 'message' => 'An URL is missing'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Extraction docId
        $docId = $this->episciences->getDocIdFromUrl($url);
        $this->logger->info('API called for docid: ', [$docId]);

        if ($docId === "") {
            return new JsonResponse(
                ['status' => Response::HTTP_BAD_REQUEST, 'message' => 'A docid is missing'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Détermination du type de références (ternaire pour plus de concision)
        $type = ($request->query->has('all') && $request->query->get('all') === "1") ? 'all' : 'accepted';
        $this->logger->info("API called for {$type} references with docid", [$docId]);

        // Récupération des références
        $refs = $this->references->getReferences($docId, $type);

        if (empty($refs)) {
            $this->logger->info('API called but no references were found for: ', [$docId]);
            return new JsonResponse(
                ['status' => Response::HTTP_OK, 'message' => 'No reference found'],
                Response::HTTP_OK
            );
        }

        return new JsonResponse($refs, Response::HTTP_OK);
    }
}