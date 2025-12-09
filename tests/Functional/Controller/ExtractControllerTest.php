<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

class ExtractControllerTest extends WebTestCase
{
    /**
     * Authentifie un client pour les tests fonctionnels
     */
    private function authenticateClient($client, string $username = 'test_user', array $roles = ['ROLE_USER']): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();

        $firewallName = 'main';

        // Créer un objet User au lieu de passer un string
        $user = new InMemoryUser($username, 'test', $roles);
        $token = new UsernamePasswordToken($user, $firewallName, $roles);

        $session->set('_security_'.$firewallName, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());
        $client->getCookieJar()->set($cookie);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testExtract_FirstTimeExtraction(): void
    {
        // This test requires full integration setup with:
        // - Mock GROBID service
        // - Mock Episciences API
        // - Test database with fixtures

        $client = static::createClient();

        // Authentifier le client avant la requête
        $this->authenticateClient($client);

        $client->request('GET', '/extract?url=https://episciences.org/test/123456');

        // Test que la route est accessible avec authentification
        // Le test peut échouer sur la logique métier (PDF non trouvé, etc.) mais pas sur l'auth
        $this->assertLessThan(500, $client->getResponse()->getStatusCode(),
            'Extract route should be accessible with authentication');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testExtract_DocumentAlreadyExists(): void
    {
        $client = static::createClient();

        // Authentifier le client
        $this->authenticateClient($client);

        // TODO: Load fixtures with existing document
        // TODO: Verify redirect to viewref without re-extraction

        // Placeholder - route accessibility test
        $client->request('GET', '/extract?url=https://episciences.org/test/123456');

        $this->assertLessThan(500, $client->getResponse()->getStatusCode(),
            'Extract route should not throw server error');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testViewReference_SaveReferences(): void
    {
        $client = static::createClient();

        // Authentifier le client
        $this->authenticateClient($client);

        // TODO: Load fixtures with document and references
        // TODO: Submit form with reference modifications

        // Placeholder - test route exists
        $client->request('GET', '/fr/viewref/123456');

        // Route exists (may redirect or return 404 for non-existent docId)
        $this->assertLessThan(500, $client->getResponse()->getStatusCode(),
            'ViewReference route should not cause server error');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testViewReference_Unauthorized(): void
    {
        $client = static::createClient();

        // Authentifier avec utilisateur non autorisé (sans rôles)
        $this->authenticateClient($client, 'test_unauthorized', []);

        // TODO: Verify AccessDeniedException or 403 response based on actual permissions

        $client->request('GET', '/fr/viewref/999999');

        // Should redirect, deny, or return 404 (non-existent doc)
        // Placeholder assertion - specific behavior depends on access_control rules
        $this->assertLessThan(500, $client->getResponse()->getStatusCode(),
            'Route should handle unauthorized access gracefully');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testViewReference_ImportBibtex(): void
    {
        $client = static::createClient();

        // Authentifier le client avec utilisateur autorisé
        $this->authenticateClient($client);

        // TODO: Load fixtures with document
        // TODO: Create temporary BibTeX file
        // TODO: Submit import form
        // TODO: Verify references imported successfully

        // Placeholder - verify route exists
        $client->request('GET', '/fr/viewref/123456');

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertLessThan(500, $statusCode,
            'ViewReference route should be accessible with authentication'
        );
    }

    /**
     * Helper method to create a temporary BibTeX file for testing
     */
    private function createTestBibtexFile(): string
    {
        $bibtexContent = <<<'BIBTEX'
@article{test2024,
  author = {Doe, John},
  title = {Test Article for Import},
  journal = {Test Journal},
  year = {2024}
}
BIBTEX;

        $tmpFile = tempnam(sys_get_temp_dir(), 'bibtex_test_');
        file_put_contents($tmpFile, $bibtexContent);

        return $tmpFile;
    }

    /**
     * Helper to create UploadedFile for form submission
     */
    private function createUploadedBibtexFile(): UploadedFile
    {
        $path = $this->createTestBibtexFile();
        return new UploadedFile($path, 'test.bib', 'text/plain', null, true);
    }
}
