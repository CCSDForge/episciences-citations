<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Services\Episciences;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Functional tests for SemanticScholarImportController.
 *
 * Authentication note: the `test` firewall config for `main` fully disables
 * security (`security: false`), so simply stuffing a token into the session
 * cookie (as some legacy tests do) never actually reaches the token storage
 * for the request. Instead we set the token directly on the container's
 * `security.token_storage` before dispatching the request — the kernel is
 * not rebooted between `getContainer()` and `request()` within a single
 * test, so the token is honored by the `#[IsGranted]` check.
 *
 * CSRF validation is stubbed the same way: `security.csrf.token_manager` is
 * swapped for an in-memory double, since the real session-backed manager
 * needs an active HTTP session that isn't reliably available outside of a
 * request cycle in this environment.
 */
class SemanticScholarImportControllerTest extends WebTestCase
{
    private const string ROUTE = '/en/viewref/12345/import-semantic-scholar';

    private function authenticatedToken(string $uid = '999'): UsernamePasswordToken
    {
        $user = new InMemoryUser('test_user', 'test', ['ROLE_USER']);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $token->setAttributes(['UID' => $uid]);

        return $token;
    }

    private function stubCsrfManager(bool $valid): CsrfTokenManagerInterface
    {
        return new class($valid) implements CsrfTokenManagerInterface {
            public function __construct(private readonly bool $valid)
            {
            }

            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'stub-token');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'stub-token');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $this->valid;
            }
        };
    }

    private function authenticate(bool $authorized = true): void
    {
        static::getContainer()->get('security.token_storage')->setToken($this->authenticatedToken());

        $episciencesStub = $this->createStub(Episciences::class);
        $episciencesStub->method('getRightUser')->willReturn($authorized);
        static::getContainer()->set(Episciences::class, $episciencesStub);
    }

    // -------------------------------------------------------------------------
    // Authentication / authorization branches
    // -------------------------------------------------------------------------

    #[Test]
    public function testImport_Unauthenticated_ReturnsForbidden(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'irrelevant', 'paperId' => 'abc']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testImport_InvalidCsrfToken_ReturnsForbidden(): void
    {
        $client = static::createClient();
        static::getContainer()->get('security.token_storage')->setToken($this->authenticatedToken());
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(false));

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'wrong-token', 'paperId' => 'abc']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testImport_UserNotAuthorizedForDocument_ReturnsForbidden(): void
    {
        $client = static::createClient();
        static::getContainer()->get('security.token_storage')->setToken($this->authenticatedToken());
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(true));

        $episciencesStub = $this->createStub(Episciences::class);
        $episciencesStub->method('getRightUser')->willReturn(false);
        static::getContainer()->set(Episciences::class, $episciencesStub);

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'stub-token', 'paperId' => 'abc']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    // -------------------------------------------------------------------------
    // Validation branch — missing/blank paperId
    // -------------------------------------------------------------------------

    #[Test]
    public function testImport_MissingPaperId_ReturnsBadRequest(): void
    {
        $client = static::createClient();
        $this->authenticate();
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(true));

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'stub-token']);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Please enter a paper ID.', $data['error']);
    }

    #[Test]
    public function testImport_BlankPaperId_ReturnsBadRequest(): void
    {
        $client = static::createClient();
        $this->authenticate();
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(true));

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'stub-token', 'paperId' => '   ']);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    // -------------------------------------------------------------------------
    // Import failure branch — importer throws RuntimeException
    // -------------------------------------------------------------------------

    #[Test]
    public function testImport_ImporterThrowsRuntimeException_ReturnsSuccessFalseWithMessage(): void
    {
        $client = static::createClient();
        $this->authenticate();
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(true));

        $referencesStub = $this->createStub(References::class);
        $referencesStub->method('getReferences')->willReturn([]);
        static::getContainer()->set(References::class, $referencesStub);

        $importerStub = $this->createStub(SemanticScholarImporter::class);
        $importerStub->method('importByPaperId')->willThrowException(new \RuntimeException('No references found for this paper ID'));
        static::getContainer()->set(SemanticScholarImporter::class, $importerStub);

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'stub-token', 'paperId' => 'bad-paper-id']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No references found for this paper ID', $data['error']);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function testImport_Success_ReturnsCountAndMessage(): void
    {
        $client = static::createClient();
        $this->authenticate();
        static::getContainer()->set('security.csrf.token_manager', $this->stubCsrfManager(true));

        $existingReferences = [1, 2];
        $referencesStub = $this->createStub(References::class);
        $referencesStub->method('getReferences')->willReturn($existingReferences);
        static::getContainer()->set(References::class, $referencesStub);

        $importerMock = $this->createMock(SemanticScholarImporter::class);
        $importerMock->expects($this->once())
            ->method('importByPaperId')
            ->with('good-paper-id', 12345, count($existingReferences))
            ->willReturn(3);
        static::getContainer()->set(SemanticScholarImporter::class, $importerMock);

        $client->request(Request::METHOD_POST, self::ROUTE, ['_token' => 'stub-token', 'paperId' => '  good-paper-id  ']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(3, $data['count']);
        $this->assertSame('3 reference(s) imported from Semantic Scholar', $data['message']);
    }
}
