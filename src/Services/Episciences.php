<?php
namespace App\Services;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Episciences {

    public function __construct(private EntityManagerInterface $entityManager,
                                private HttpClientInterface $client,
                                private ContainerBagInterface $params,
                                private string $pdfFolder)
    {
    }

    /**
     * @param string $rvCode
     * @param int $docId
     * @return array|bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getPaperPDF(string $rvCode, int $docId): array|bool
    {
        if (!file_exists($this->pdfFolder.$docId.'.pdf')) {

        if ($this->params->get('kernel.environment') === "dev") {
            $domain = "http://";
        } else {
            $domain = "https://";
        }
        try {
            $response = $this->client->request('GET', $domain . $rvCode . '.episciences.org/' . $docId . '/pdf', [
                'headers' => [
                    "Accept" => "application/octet-stream"
                ]
            ])->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            return ['status' => $e->getCode(), 'message' => $e->getMessage()];
        }
            return $this->putPdfInCache($docId, $response);
        }
        return true;
    }
    public function putPdfInCache($name, $response): bool
    {
        $fp = fopen($this->pdfFolder.$name.'.pdf', 'wb');
        if (fwrite($fp, $response) === FALSE) {
            return false;
        }
        fclose($fp);
        return true;
    }
}