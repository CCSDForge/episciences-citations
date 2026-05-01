<?php

namespace App\Tests\Functional\Controller;

use App\Services\Grobid;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

class ExtractControllerTest extends WebTestCase
{
    private const string TEST_TOKEN = 'test-extract-token';

    private function authenticateClient(KernelBrowser $client, string $username = 'test_user', array $roles = ['ROLE_USER']): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();

        $firewallName = 'main';
        $user = new InMemoryUser($username, 'test', $roles);
        $token = new UsernamePasswordToken($user, $firewallName, $roles);

        $session->set('_security_'.$firewallName, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());
        $client->getCookieJar()->set($cookie);
    }

    private function authHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TEST_TOKEN];
    }

    // -------------------------------------------------------------------------
    // GET /api/extract — authentication
    // -------------------------------------------------------------------------

    #[Test]
    public function testApiExtract_Unauthorized_WhenTokenMissing(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/extract?url=https://episciences.org/article/view/12345');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Unauthorized', $data['error']);
    }

    #[Test]
    public function testApiExtract_Unauthorized_WhenTokenIsWrong(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/extract?url=https://episciences.org/article/view/12345', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    // -------------------------------------------------------------------------
    // GET /api/extract — input validation (no DB needed)
    // -------------------------------------------------------------------------

    #[Test]
    public function testApiExtract_MissingUrl(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/extract', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('url', $data['error']);
    }

    #[Test]
    public function testApiExtract_InvalidUrl(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/extract?url=https://example.com/no-numeric-id', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('document ID', $data['error']);
    }

    // -------------------------------------------------------------------------
    // GET /api/extract — already extracted (Grobid mocked, no real DB)
    // -------------------------------------------------------------------------

    #[Test]
    public function testApiExtract_AlreadyExtracted_ReturnsImmediately(): void
    {
        $client = static::createClient();

        $grobidMock = $this->createMock(Grobid::class);
        $grobidMock->method('countAllReferencesFromDB')->willReturn(3);
        static::getContainer()->set(Grobid::class, $grobidMock);

        $client->request(Request::METHOD_GET, '/api/extract?url=https://episciences.org/article/view/12345', [], [], $this->authHeaders());

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['alreadyExtracted']);
        $this->assertEquals(3, $data['referenceCount']);
        $this->assertEquals(12345, $data['docId']);
    }

    #[Test]
    public function testApiExtract_AlreadyExtracted_DoesNotCallInsertReferences(): void
    {
        $client = static::createClient();

        $grobidMock = $this->createMock(Grobid::class);
        $grobidMock->method('countAllReferencesFromDB')->willReturn(5);
        $grobidMock->expects($this->never())->method('insertReferences');
        static::getContainer()->set(Grobid::class, $grobidMock);

        $client->request(Request::METHOD_GET, '/api/extract?url=https://episciences.org/article/view/99999', [], [], $this->authHeaders());

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // GET /api/extract — PDF not found (Grobid mocked, Episciences returns error)
    // -------------------------------------------------------------------------

    #[Test]
    public function testApiExtract_PdfNotFound_ReturnsJsonError(): void
    {
        $client = static::createClient();

        $grobidMock = $this->createMock(Grobid::class);
        $grobidMock->method('countAllReferencesFromDB')->willReturn(0);
        static::getContainer()->set(Grobid::class, $grobidMock);

        $client->request(Request::METHOD_GET, '/api/extract?url=https://episciences.org/article/view/99999999', [], [], $this->authHeaders());

        $response = $client->getResponse();
        $this->assertResponseHeaderSame('content-type', 'application/json');
        $this->assertContains(
            $response->getStatusCode(),
            [Response::HTTP_NOT_FOUND, Response::HTTP_BAD_GATEWAY, Response::HTTP_OK],
            'Should return a JSON error when Episciences cannot provide the PDF'
        );
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
    }

    // -------------------------------------------------------------------------
    // Legacy web routes — basic accessibility checks
    // -------------------------------------------------------------------------

    #[Test]
    public function testExtract_FirstTimeExtraction(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);

        $client->request(Request::METHOD_GET, '/extract?url=https://episciences.org/test/123456');

        $this->assertLessThan(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $client->getResponse()->getStatusCode(),
            'Extract route should be accessible with authentication'
        );
    }

    #[Test]
    public function testExtract_DocumentAlreadyExists(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);

        $client->request(Request::METHOD_GET, '/extract?url=https://episciences.org/test/123456');

        $this->assertLessThan(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $client->getResponse()->getStatusCode(),
            'Extract route should not throw server error'
        );
    }

    #[Test]
    public function testViewReference_SaveReferences(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);

        $client->request(Request::METHOD_GET, '/fr/viewref/123456');

        $this->assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testViewReference_Unauthorized(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, 'test_unauthorized', []);

        $client->request(Request::METHOD_GET, '/fr/viewref/999999');

        $this->assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testViewReference_ImportBibtex(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);

        $client->request(Request::METHOD_GET, '/fr/viewref/123456');

        $this->assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $client->getResponse()->getStatusCode());
    }
}
