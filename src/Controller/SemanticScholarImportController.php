<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Episciences;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the "import references from Semantic Scholar" action on the reference-edit
 * page, split out of the former monolithic ExtractController to stay under
 * Sonar's 20-method-per-class limit (S1448).
 */
class SemanticScholarImportController extends AbstractController
{
    use DocumentAccessTrait;

    public function __construct(
        private readonly References $references,
        private readonly Episciences $episciences,
        private readonly SemanticScholarImporter $semanticsScholarImporter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{_locale<en|fr>}/viewref/{docId}/import-semantic-scholar', name: 'app_import_semantic_scholar', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function importFromSemanticScholar(int $docId, Request $request): JsonResponse
    {
        $authError = $this->validateSemanticScholarRequest($docId, $request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->performSemanticScholarImport($docId, $request);
    }

    private function validateSemanticScholarRequest(int $docId, Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('import-semantic-scholar', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }
        if (!$this->isAuthorizeForApp($this->episciences, $docId)) {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function performSemanticScholarImport(int $docId, Request $request): JsonResponse
    {
        $paperId = trim((string) $request->request->get('paperId', ''));
        if ($paperId === '') {
            return new JsonResponse(
                ['success' => false, 'error' => $this->translator->trans('Please enter a paper ID.')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $startOrder = count($this->references->getReferences($docId, 'all'));

        try {
            $count = $this->semanticsScholarImporter->importByPaperId($paperId, $docId, $startOrder);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }

        return new JsonResponse([
            'success' => true,
            'count'   => $count,
            'message' => $this->translator->trans('%count% reference(s) imported from Semantic Scholar', ['%count%' => $count]),
        ]);
    }
}
