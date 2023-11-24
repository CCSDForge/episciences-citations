<?php
namespace App\Services;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
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
                                private string $pdfFolder,
                                private string $apiRight)
    {
    }

    /**
     * @param string $url
     * @return array|bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getPaperPDF(string $url): array|bool
    {
        $this->createDirDataPdf();
        $docId = $this->getDocIdFromUrl($url);
        if ($docId !== '' && !file_exists($this->pdfFolder.$docId.'.pdf')) {
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    "Accept" => "application/octet-stream"
                ]
            ])->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            $message = $this->manageHttpErrorMessagePDF($e->getCode(),$e->getMessage());
            return ['status' => $e->getCode(), 'message' => $message];
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

    /**
     * @param int $status
     * @param string $message
     * @return string
     */
    public function manageHttpErrorMessagePDF(int $status, string $message) {

        if ($status === 404) {
            $message = "PDF not found at the destined address";
        }
        return $message;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getDocIdFromUrl(string $url) :string {
        $explodeUrl = explode('/',$url);
        foreach ($explodeUrl as $item) {
            if (preg_match('/^\d*$/', $item) && $item !== ""){
                return $item;
            }
        }
        return '';
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getRightUser($docId, $uid): bool {
        try {
            $response = $this->client->request('GET', $this->apiRight."/api/users/" . $uid . "/is-allowed-to-edit-citations?documentId=" . $docId)->getContent();
            return $response;
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            return false;
        }
    }

    /**
     * @return void
     */
    public function createDirDataPdf(): void
    {
        if (!file_exists($this->pdfFolder) && !mkdir($concurrentDirectory = $this->pdfFolder) && !is_dir($concurrentDirectory)) {
            exit();
        }
    }
}