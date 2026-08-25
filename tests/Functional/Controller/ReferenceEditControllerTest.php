<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Services\Doi;
use App\Services\Episciences;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Functional tests for ReferenceEditController: the viewref page (GET + the
 * save/add-reference/import-bibtex submit branches), the autosave endpoint and
 * the DOI-enrichment endpoint.
 *
 * Test-environment notes:
 * - The "main" firewall has `security: false` under config/packages/security.yaml
 *   `when@test`, so no firewall listener ever restores a token from the session.
 *   Authentication is instead injected directly into `security.token_storage`.
 * - `security.token_storage` is tagged `kernel.reset` (method: setToken), and
 *   Symfony's Kernel::boot() replays that reset at the start of every request
 *   after the first in a test (once requestStackSize returns to 0). A token set
 *   once in test code is therefore wiped before a *second* request reaches the
 *   controller. authenticate() below works around this by registering a
 *   kernel.REQUEST listener (which runs after that reset) so the token is
 *   re-applied for every request performed for the rest of the test.
 * - Doctrine: `Document`/`PaperReferences` entities created directly in the test
 *   share the EntityManager with the controller (no kernel reboot), so
 *   `$this->entityManager->clear()` is called after seeding fixtures and after
 *   each request to force real re-queries instead of returning identity-mapped,
 *   stale in-memory collections.
 */
class ReferenceEditControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private bool $authorizationAllowed = true;
    private bool $episciencesMockRegistered = false;

    protected function setUp(): void
    {
        // The container is compiled with DATABASE_URL resolved from the real
        // OS environment (docker-compose exports a MySQL DSN even for
        // APP_ENV=test), which takes precedence over .env.test's sqlite
        // value. Force a fresh in-memory SQLite database for this process
        // before booting the kernel, matching the other integration/repository
        // tests, so this stays fast, hermetic, and independent of any real
        // database's permissions.
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->dropSchema($metadata);
        } catch (\Throwable) {
            // Schema may not exist yet on the very first run.
        }
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->entityManager);
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /**
     * Injects a token directly into the token storage, and keeps re-injecting
     * it via a kernel.REQUEST listener so it survives the per-request reset
     * (see class docblock). Must be called before the first request that needs
     * authentication.
     */
    private function authenticate(string $uid, array $roles = ['ROLE_USER']): TokenInterface
    {
        $user = new InMemoryUser('test_user_' . $uid, 'test', $roles);
        $token = new UsernamePasswordToken($user, 'main', $roles);
        $token->setAttribute('UID', $uid);
        $token->setAttribute('FIRSTNAME', 'John');
        $token->setAttribute('LASTNAME', 'Doe');

        static::getContainer()->get('security.token_storage')->setToken($token);
        static::getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::REQUEST,
            static function () use ($token): void {
                static::getContainer()->get('security.token_storage')->setToken($token);
            },
            4096
        );

        return $token;
    }

    /**
     * The Symfony test container refuses to `->set()` a service that has
     * already been instantiated/used by a previous request in the same test
     * (e.g. switching a test from "authorized" to "denied" mid-test to check
     * that a second request is rejected). The stub is therefore registered
     * only once, and subsequent calls just flip the flag its callback reads.
     */
    private function mockAuthorization(bool $allowed): void
    {
        $this->authorizationAllowed = $allowed;

        if ($this->episciencesMockRegistered) {
            return;
        }

        $episciencesMock = $this->createStub(Episciences::class);
        $episciencesMock->method('getRightUser')->willReturnCallback(fn (): bool => $this->authorizationAllowed);
        static::getContainer()->set(Episciences::class, $episciencesMock);
        $this->episciencesMockRegistered = true;
    }

    private function persistUser(int $id = 1001): UserInformations
    {
        $user = new UserInformations();
        $user->setId($id);
        $user->setName('Doe');
        $user->setSurname('John');
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistDocument(int $docId): Document
    {
        $document = new Document();
        $document->setId($docId);
        $this->entityManager->persist($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function persistReference(
        Document $document,
        array $reference,
        int $accepted = 0,
        int $order = 0,
        string $source = PaperReferences::SOURCE_METADATA_GROBID,
        ?UserInformations $user = null
    ): PaperReferences {
        $paperReference = new PaperReferences();
        $paperReference->setReference($reference);
        $paperReference->setSource($source);
        $paperReference->setAccepted($accepted);
        $paperReference->setReferenceOrder($order);
        $paperReference->setDocument($document);
        $paperReference->setUpdatedAt(new \DateTimeImmutable());
        if ($user instanceof UserInformations) {
            $paperReference->setUid($user);
        }
        $this->entityManager->persist($paperReference);

        return $paperReference;
    }

    private function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * The "autosave" CSRF token (unlike the form's "submit" one) is a classic
     * session-bound token, so it can only be generated/validated from within a
     * real request that has a session — fetching it via the container directly
     * (no request in flight) throws SessionNotFoundException. This performs a
     * throwaway GET of the viewref page (which starts the session and embeds
     * the token in `data-csrf-token`) and returns that value for reuse in a
     * subsequent autosave POST on the same client (same session cookie).
     */
    private function fetchAutosaveCsrfToken(int $docId): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/' . $docId);

        return (string) $crawler->filter('#form-extraction')->attr('data-csrf-token');
    }

    // -------------------------------------------------------------------------
    // GET /{_locale}/viewref/{docId} — access control
    // -------------------------------------------------------------------------

    #[Test]
    public function testViewReference_UserNotAllowedOnDocument_ReturnsForbidden(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(false);

        $this->client->request(Request::METHOD_GET, '/en/viewref/123456');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testViewReference_UnauthorizedAccess_DoesNotCreateDocumentStub(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(false);

        $this->client->request(Request::METHOD_GET, '/en/viewref/555555');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertNull(
            $this->entityManager->getRepository(Document::class)->find(555555),
            'A denied request must not create a document row as a side effect'
        );
    }

    // -------------------------------------------------------------------------
    // GET /{_locale}/viewref/{docId} — happy paths
    // -------------------------------------------------------------------------

    #[Test]
    public function testViewReference_DocumentNotYetExtracted_CreatesDocumentStubAndRenders(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $this->client->request(Request::METHOD_GET, '/en/viewref/777777');

        $this->assertResponseIsSuccessful();
        $this->assertInstanceOf(
            Document::class,
            $this->entityManager->getRepository(Document::class)->find(777777),
            'Visiting an unknown docId must create a stub Document'
        );
    }

    #[Test]
    public function testViewReference_ExistingReferences_AreRenderedInPage(): void
    {
        $document = $this->persistDocument(123456);
        $this->persistReference($document, ['raw_reference' => 'Doe John. Some Distinctive Title. 2024.']);
        $this->flush();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Doe John. Some Distinctive Title. 2024.', $crawler->text());
    }

    // -------------------------------------------------------------------------
    // POST /{_locale}/viewref/{docId} — save button: accept/decline persistence
    // -------------------------------------------------------------------------

    #[Test]
    public function testViewReference_SaveWithAcceptedChange_PersistsNewAcceptedState(): void
    {
        $document = $this->persistDocument(123456);
        $user = $this->persistUser(1001);
        $reference = $this->persistReference($document, ['raw_reference' => 'A reference'], accepted: 0, user: $user);
        $this->flush();
        $refId = $reference->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->filter('#document_save')->form();
        $form['document[paperReferences][0][accepted]'] = '1';

        $this->client->submit($form);

        $this->assertResponseRedirects('http://localhost/en/viewref/123456');

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        $this->assertSame(1, $persisted->getAccepted());
    }

    #[Test]
    public function testViewReference_SaveWithModifiedText_MarksSourceAsUser(): void
    {
        $document = $this->persistDocument(123456);
        $reference = $this->persistReference(
            $document,
            ['raw_reference' => 'Original text'],
            accepted: 1,
            source: PaperReferences::SOURCE_METADATA_GROBID
        );
        $this->flush();
        $refId = $reference->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->filter('#document_save')->form();
        $form['document[paperReferences][0][reference]'] = json_encode(['raw_reference' => 'Edited text']);
        $form['document[paperReferences][0][isDirtyTextAreaModifyRef]'] = '1';

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        $this->assertSame(PaperReferences::SOURCE_METADATA_EPI_USER, $persisted->getSource());
        $this->assertSame('Edited text', $persisted->getReference()['raw_reference']);
    }

    #[Test]
    public function testViewReference_SaveWithNoChanges_DoesNotAlterReference(): void
    {
        $document = $this->persistDocument(123456);
        $reference = $this->persistReference($document, ['raw_reference' => 'A reference'], accepted: 1);
        $this->flush();
        $refId = $reference->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->filter('#document_save')->form();

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        $this->assertSame(1, $persisted->getAccepted());
    }

    #[Test]
    public function testViewReference_SaveWithDeleteCheckbox_RemovesReference(): void
    {
        $document = $this->persistDocument(123456);
        $reference = $this->persistReference($document, ['raw_reference' => 'To be deleted'], accepted: 1);
        $this->flush();
        $refId = $reference->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->filter('#document_save')->form();
        $form['document[paperReferences][0][checkboxIdTodelete]']->tick();

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $this->assertNull($this->entityManager->getRepository(PaperReferences::class)->find($refId));
    }

    // -------------------------------------------------------------------------
    // POST /{_locale}/viewref/{docId} — add a new reference by hand
    // -------------------------------------------------------------------------

    #[Test]
    public function testViewReference_AddNewReference_PersistsAndAccepts(): void
    {
        $this->persistDocument(123456);
        $this->persistUser(1001);
        $this->flush();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->selectButton('document[submitNewRef]')->form();
        $form['document[addReference]'] = 'A brand new hand-typed reference';

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $document = $this->entityManager->getRepository(Document::class)->find(123456);
        $this->assertCount(1, $document->getPaperReferences());

        $newReference = $document->getPaperReferences()->first();
        $this->assertSame('A brand new hand-typed reference', $newReference->getReference()['raw_reference']);
        $this->assertSame(1, $newReference->getAccepted());
        $this->assertSame(PaperReferences::SOURCE_METADATA_EPI_USER, $newReference->getSource());
    }

    #[Test]
    public function testViewReference_AddNewReference_FirstTimeCasUserIsCreatedOnTheFly(): void
    {
        // Regression test: References::addNewReference() used to forward the CAS
        // "UID" token attribute (a string) straight to UserInformations::setId(?int)
        // without casting it, unlike its sibling References::resolveOrCreateUser().
        // Under strict_types=1 that threw a TypeError (uncaught 500) the first time
        // a CAS user not yet present in `user_informations` added a hand-typed
        // reference. Deliberately do NOT pre-persist the user here, so this exercises
        // the on-the-fly creation path and would fail again if the cast regresses.
        $this->persistDocument(123456);
        $this->flush();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->selectButton('document[submitNewRef]')->form();
        $form['document[addReference]'] = 'A brand new hand-typed reference';

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(UserInformations::class)->find(1001);
        $this->assertNotNull($user);

        $document = $this->entityManager->getRepository(Document::class)->find(123456);
        $newReference = $document->getPaperReferences()->first();
        $this->assertSame(1001, $newReference->getUid()->getId());
    }

    #[Test]
    public function testViewReference_AddNewReferenceWithoutTitle_DoesNotPersist(): void
    {
        $document = $this->persistDocument(123456);
        $this->flush();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->selectButton('document[submitNewRef]')->form();
        // addReference left blank on purpose.

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(Document::class)->find(123456);
        $this->assertCount(0, $refreshed->getPaperReferences());
    }

    // -------------------------------------------------------------------------
    // POST /{_locale}/viewref/{docId} — BibTeX import
    // -------------------------------------------------------------------------

    #[Test]
    public function testViewReference_ImportBibtexWithoutFile_ShowsFlashErrorAndDoesNotPersist(): void
    {
        $document = $this->persistDocument(123456);
        $this->flush();

        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/en/viewref/123456');
        $form = $crawler->selectButton('document[submitImportBib]')->form();

        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertStringContainsString('Please add a BibTeX file', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(Document::class)->find(123456);
        $this->assertCount(0, $refreshed->getPaperReferences());
    }

    // -------------------------------------------------------------------------
    // POST /{_locale}/viewref/{docId}/autosave
    // -------------------------------------------------------------------------

    #[Test]
    public function testAutosave_MissingCsrfToken_ReturnsForbidden(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(true);

        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            'orderRef' => '1;2',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid CSRF token', $data['error']);
    }

    #[Test]
    public function testAutosave_UserNotAllowedOnDocument_ReturnsForbidden(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        $this->mockAuthorization(false);
        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'orderRef' => '1;2',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Access Denied', $data['error']);
    }

    #[Test]
    public function testAutosave_WithOrderRef_PersistsNewOrder(): void
    {
        $document = $this->persistDocument(123456);
        $refA = $this->persistReference($document, ['raw_reference' => 'A'], order: 0);
        $refB = $this->persistReference($document, ['raw_reference' => 'B'], order: 1);
        $this->flush();
        $idA = $refA->getId();
        $idB = $refB->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'orderRef' => $idB . ';' . $idA,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $this->entityManager->clear();
        $this->assertSame(0, $this->entityManager->getRepository(PaperReferences::class)->find($idB)->getReferenceOrder());
        $this->assertSame(1, $this->entityManager->getRepository(PaperReferences::class)->find($idA)->getReferenceOrder());
    }

    #[Test]
    public function testAutosave_WithRefId_PersistsReferenceAndReturnsIt(): void
    {
        $document = $this->persistDocument(123456);
        $reference = $this->persistReference($document, ['raw_reference' => 'Original']);
        $this->flush();
        $refId = $reference->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'refId' => (string) $refId,
            'reference' => json_encode(['raw_reference' => 'Autosaved text']),
            'accepted' => '1',
            'isDirty' => '1',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Autosaved text', $data['reference']['raw_reference']);

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(PaperReferences::class)->find($refId);
        $this->assertSame('Autosaved text', $persisted->getReference()['raw_reference']);
        $this->assertSame(1, $persisted->getAccepted());
        $this->assertSame(PaperReferences::SOURCE_METADATA_EPI_USER, $persisted->getSource());
    }

    #[Test]
    public function testAutosave_WithOrderRef_IgnoresReferencesFromOtherDocument(): void
    {
        $ownDocument = $this->persistDocument(123456);
        $ownRef = $this->persistReference($ownDocument, ['raw_reference' => 'Own'], order: 0);

        $otherDocument = $this->persistDocument(999000);
        $otherRef = $this->persistReference($otherDocument, ['raw_reference' => 'Other'], order: 0);
        $this->flush();
        $ownId = $ownRef->getId();
        $otherId = $otherRef->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        // Authorized only for docId 123456, but the orderRef payload also targets
        // a reference belonging to a different document (999000).
        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'orderRef' => $otherId . ';' . $ownId,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $this->entityManager->clear();
        // The reference from the other document must keep its original order.
        $this->assertSame(0, $this->entityManager->getRepository(PaperReferences::class)->find($otherId)->getReferenceOrder());
        $this->assertSame(1, $this->entityManager->getRepository(PaperReferences::class)->find($ownId)->getReferenceOrder());
    }

    #[Test]
    public function testAutosave_WithRefId_FromOtherDocument_ReturnsError(): void
    {
        $otherDocument = $this->persistDocument(999000);
        $otherRef = $this->persistReference($otherDocument, ['raw_reference' => 'Other'], order: 0);
        $this->flush();
        $otherId = $otherRef->getId();

        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        // Authorized only for docId 123456, but refId targets a reference
        // belonging to a different document (999000).
        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'refId' => (string) $otherId,
            'reference' => json_encode(['raw_reference' => 'Tampered']),
            'accepted' => '1',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Reference not found or access denied', $data['error']);

        $this->entityManager->clear();
        $this->assertSame('Other', $this->entityManager->getRepository(PaperReferences::class)->find($otherId)->getReference()['raw_reference']);
    }

    #[Test]
    public function testAutosave_WithUnknownRefId_ReturnsError(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
            'refId' => '999999',
            'reference' => json_encode(['raw_reference' => 'Does not matter']),
            'accepted' => '1',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Reference not found or access denied', $data['error']);
    }

    #[Test]
    public function testAutosave_WithNoRecognizedData_ReturnsSuccessFalse(): void
    {
        $this->authenticate('1001');
        $this->mockAuthorization(true);
        $token = $this->fetchAutosaveCsrfToken(123456);

        $this->client->request(Request::METHOD_POST, '/en/viewref/123456/autosave', [
            '_token' => $token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No data to save', $data['error']);
    }

    // -------------------------------------------------------------------------
    // GET /doi/enrich
    // -------------------------------------------------------------------------

    #[Test]
    public function testEnrichFromDoi_MissingDoi_ReturnsBadRequest(): void
    {
        $this->authenticate('1001');

        $this->client->request(Request::METHOD_GET, '/doi/enrich');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('DOI is required', $data['error']);
    }

    #[Test]
    public function testEnrichFromDoi_UnknownDoi_ReturnsNotFound(): void
    {
        $this->authenticate('1001');

        $doiMock = $this->createStub(Doi::class);
        $doiMock->method('getFormattedCitation')->willReturn('');
        $doiMock->method('getCsl')->willReturn('');
        static::getContainer()->set(Doi::class, $doiMock);

        $this->client->request(Request::METHOD_GET, '/doi/enrich?doi=10.9999/unknown');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Could not fetch data for this DOI', $data['error']);
    }

    #[Test]
    public function testEnrichFromDoi_KnownDoi_ReturnsCitationAndCsl(): void
    {
        $this->authenticate('1001');

        $doiMock = $this->createStub(Doi::class);
        $doiMock->method('getFormattedCitation')->willReturn('Doe, J. (2024). A Test Article.');
        $doiMock->method('getCsl')->willReturn(json_encode(['title' => 'A Test Article']));
        static::getContainer()->set(Doi::class, $doiMock);

        $this->client->request(Request::METHOD_GET, '/doi/enrich?doi=10.1234/test');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Doe, J. (2024). A Test Article.', $data['citation']);
        $this->assertSame('A Test Article', $data['csl']['title']);
    }
}
