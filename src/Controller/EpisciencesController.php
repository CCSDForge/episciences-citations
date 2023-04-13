<?php
namespace App\Controller;

use App\Services\Episciences;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class EpisciencesController extends AbstractController{
    public function __construct(private Episciences $episciences)
    {
    }


    #[Route('/getpdf/{rvcode}/{docId}', name: 'app_epi_service_get_references')]
    public function getPdfFromEpisciences(string $rvcode,int $docId)
    {
        $this->episciences->getPaperPDF($rvcode,$docId);
    }
}