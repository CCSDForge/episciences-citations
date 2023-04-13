<?php
namespace App\Services;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Episciences {

    public function __construct(private EntityManagerInterface $entityManager,
                                private HttpClientInterface $client,
                                private ContainerBagInterface $params,
                                private string $pdfFolder)
    {
    }

    public function getPaperPDF(string $rvCode,int $docId)
    {
        if ($this->params->get('kernel.environment') === "dev")
        {
            $domain = "http://";
        } else {
            $domain = "https://";
        }
        $response = $this->client->request('GET',$domain.$rvCode.'.episciences.org/'.$docId.'/pdf',[
            'headers'=> [
                "Accept" => "application/octet-stream"
            ]
        ])->getContent();
        $this->putPdfInCache($docId,$response);
    }
    public function putPdfInCache($name, $response) {
        $fp = fopen($this->pdfFolder.$name.'.pdf', 'wb');
        fwrite($fp, $response);
        fclose($fp);
    }
}