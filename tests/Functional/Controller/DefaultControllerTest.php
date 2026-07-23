<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for DefaultController (login/logout/force/index).
 *
 * cas_host, cas_path, cas_login_target, cas_logout_target, cas_gateway and
 * force_https are real environment-backed parameters (see docker-compose /
 * container env: CAS_HOST, CAS_PATH, CAS_LOGIN_TARGET, CAS_LOGOUT_TARGET,
 * CAS_GATEWAY=false, FORCE_HTTPS=true) — no need to mock them.
 */
class DefaultControllerTest extends WebTestCase
{
    // -------------------------------------------------------------------------
    // GET /login
    // -------------------------------------------------------------------------

    #[Test]
    public function testLogin_RedirectsToCasServer(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('cas.ccsd.cnrs.fr', $location);
        $this->assertStringContainsString('/cas/login?service=', $location);
        $this->assertStringContainsString('/force?url=', $location);
    }

    #[Test]
    public function testLogin_WithExportBibParam_IncludesItInServiceUrl(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login?exportbib=1');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString(urlencode('&exportbib=1'), $location);
    }

    #[Test]
    public function testLogin_WithoutExportBibParam_DoesNotIncludeIt(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringNotContainsString('exportbib', $location);
    }

    #[Test]
    public function testLogin_WithHttpUrl_UpgradesToHttps(): void
    {
        // FORCE_HTTPS=true in the test container environment.
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login?url=' . urlencode('http://episciences.org/test/123'));

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('https://episciences.org/test/123', $location);
    }

    #[Test]
    public function testLogin_WithHttpsUrl_KeepsHttps(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login?url=' . urlencode('https://episciences.org/test/456'));

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('https://episciences.org/test/456', $location);
    }

    #[Test]
    public function testLogin_WithoutUrl_DoesNotError(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');

        $this->assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // GET /force
    // -------------------------------------------------------------------------

    #[Test]
    public function testForce_RedirectsToExtractWithUrl(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/force?url=' . urlencode('https://episciences.org/test/789'));

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/extract', $location);
        $this->assertStringContainsString('episciences.org', $location);
    }

    #[Test]
    public function testForce_WithExportBibParam_PropagatesIt(): void
    {
        $client = static::createClient();

        $client->request(
            Request::METHOD_GET,
            '/force?url=' . urlencode('https://episciences.org/test/789') . '&exportbib=1'
        );

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('exportbib=1', $location);
    }

    #[Test]
    public function testForce_WithoutUrl_StillRedirects(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/force');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/extract', $location);
    }

    #[Test]
    public function testForce_SetsSessionValuesForLaterExtraction(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/force?url=' . urlencode('https://episciences.org/test/321'));

        $session = $client->getRequest()->getSession();
        $this->assertSame('https://episciences.org/test/321', $session->get('EpiPdfUrltoExtract'));
        $this->assertSame(0, $session->get('isAlreadyopenModal'));
    }

    // -------------------------------------------------------------------------
    // GET / and /fr (index)
    // -------------------------------------------------------------------------

    #[Test]
    public function testIndex_RendersHomePage(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testIndex_FrenchLocale_RendersHomePage(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/fr');

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // GET /logout
    // -------------------------------------------------------------------------

    #[Test]
    public function testLogout_WhenCasClientNeverInitialized_FallsBackToLocalRedirectInsteadOf500(): void
    {
        // Regression test: hitting /logout without the phpCAS client having been
        // initialized earlier in the request used to bubble up
        // CAS_OutOfSequenceBeforeClientException as an unhandled 500. It must now
        // be caught and turned into a graceful redirect to the home page.
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/logout');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $this->assertSame('/', $client->getResponse()->headers->get('Location'));
    }
}
