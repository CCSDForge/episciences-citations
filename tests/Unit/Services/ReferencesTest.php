<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use Psr\Log\LoggerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Repository\PaperReferencesRepository;
use App\Repository\UserInformationsRepository;
use App\Services\Bibtex;
use App\Services\Grobid;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use App\Services\References;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReferencesTest extends TestCase
{
    private References $service;
    private MockObject $entityManager;
    private MockObject $grobid;
    private MockObject $bibtex;
    private MockObject $solrReferenceEnricher;
    private MockObject $openAccessReferenceEnricher;
    private MockObject $refRepository;
    private MockObject $userRepository;
    private MockObject $documentRepository;
    private MockObject $logger;

    protected function setUp(): void
    {
        // Mock EntityManager
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->grobid = $this->createMock(Grobid::class);
        $this->bibtex = $this->createMock(Bibtex::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReference')->willReturnArgument(0);
        $this->solrReferenceEnricher->method('enrichReferences')->willReturnArgument(0);
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);
        $this->openAccessReferenceEnricher->method('enrichReference')->willReturnArgument(0);
        $this->openAccessReferenceEnricher->method('enrichReferences')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock repositories
        $this->refRepository = $this->createMock(PaperReferencesRepository::class);
        $this->userRepository = $this->createMock(UserInformationsRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        // Service under test
        $this->service = new References(
            $this->entityManager,
            $this->grobid,
            $this->bibtex,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher,
            $this->logger
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_Success(): void
    {
        // Arrange
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref1 = new PaperReferences();
        $ref1->setId(1);
        $ref1->setAccepted(0);

        $ref2 = new PaperReferences();
        $ref2->setId(2);
        $ref2->setAccepted(0);

        $form = [
            'paperReferences' => [
                ['id' => 1, 'accepted' => 1, 'isDirtyTextAreaModifyRef' => '0'],
                ['id' => 2, 'accepted' => 1, 'isDirtyTextAreaModifyRef' => '1'], // Modified
            ],
            'orderRef' => '1;2'
        ];

        // Mock user repository (called multiple times in loop)
        $this->entityManager->expects($this->any())
            ->method('getRepository')
            ->willReturnCallback(function($class) use ($user, $ref1, $ref2) {
                if ($class === UserInformations::class) {
                    $repo = $this->userRepository;
                    $repo->method('find')->willReturn($user);
                    return $repo;
                }
                if ($class === PaperReferences::class) {
                    $repo = $this->refRepository;
                    $repo->method('find')->willReturnOnConsecutiveCalls($ref1, $ref2, $ref1, $ref2);
                    return $repo;
                }
            });

        // Expect single flush (optimization)
        $this->entityManager->expects($this->once())->method('flush');
        $this->entityManager->expects($this->exactly(4))->method('persist');

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('orderPersisted', $result);
        $this->assertArrayHasKey('referencePersisted', $result);
        $this->assertEquals(2, $result['orderPersisted']);
        $this->assertEquals(2, $result['referencePersisted']);

        // Verify ref2 was marked as USER source (because isDirtyTextAreaModifyRef = "1")
        $this->assertEquals(PaperReferences::SOURCE_METADATA_EPI_USER, $ref2->getSource());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithDeletions(): void
    {
        // Arrange
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $refToDelete = new PaperReferences();
        $refToDelete->setId(1);

        $form = [
            'paperReferences' => [
                ['id' => 1, 'checkboxIdTodelete' => '1'], // To be deleted
            ],
            'orderRef' => ''
        ];

        // Mock repositories (called multiple times)
        $this->entityManager->expects($this->any())
            ->method('getRepository')
            ->willReturnCallback(function($class) use ($user, $refToDelete) {
                if ($class === UserInformations::class) {
                    $repo = $this->userRepository;
                    $repo->method('find')->willReturn($user);
                    return $repo;
                }
                if ($class === PaperReferences::class) {
                    $repo = $this->refRepository;
                    $repo->method('find')->willReturn($refToDelete);
                    return $repo;
                }
            });

        // Expect remove() to be called
        $this->entityManager->expects($this->once())->method('remove')->with($refToDelete);
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertEquals(1, $result['referencePersisted']); // 1 reference deleted
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferences_AllType_ReturnsFormatted(): void
    {
        // Arrange — flat arrays, no JSON string wrapping
        $docId = 123456;

        $ref1 = new PaperReferences();
        $ref1->setId(1);
        $ref1->setReference([
            'raw_reference' => 'Test ref 1',
            'csl' => ['type' => 'article', 'title' => 'Test'],
            'detectors' => ['clayFeet', 'paperMill'],
            'status' => ['watch'],
            'pubpeerurl' => ['https://pubpeer.example/10.1234/test'],
        ]);
        $ref1->setAccepted(1);
        $ref1->setReferenceOrder(0);

        $ref2 = new PaperReferences();
        $ref2->setId(2);
        $ref2->setReference(['raw_reference' => 'Test ref 2']);
        $ref2->setAccepted(0);
        $ref2->setReferenceOrder(1);

        $formattedRef = ['raw_reference' => 'Formatted reference text'];

        // Mock Grobid service
        $this->grobid->expects($this->once())
            ->method('getAllGrobidReferencesFromDB')
            ->with($docId)
            ->willReturn([$ref1, $ref2]);

        // getCslRefText now takes an array and returns an array
        $this->bibtex->expects($this->exactly(2))
            ->method('getCslRefText')
            ->willReturn($formattedRef);

        // Act
        $result = $this->service->getReferences($docId, 'all');

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertSame($formattedRef['raw_reference'], $result[1]['ref']['raw_reference']);
        $this->assertSame(['clayFeet', 'paperMill'], $result[1]['ref']['detectors']);
        $this->assertSame(['watch'], $result[1]['ref']['status']);
        $this->assertSame(['https://pubpeer.example/10.1234/test'], $result[1]['ref']['pubpeerurl']);
        $this->assertEquals(1, $result[1]['isAccepted']);
        $this->assertEquals(0, $result[1]['referenceOrder']);
        $this->assertArrayHasKey('csl', $result[1]);    // CSL present in ref1
        $this->assertArrayNotHasKey('csl', $result[2]); // No CSL in ref2
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAddNewReference_WithDoi_Success(): void
    {
        // Arrange
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $doc = new Document();
        $doc->setId(123456);

        $form = [
            'id' => 123456,
            'addReference' => 'New test reference',
            'addReferenceDoi' => 'https://doi.org/10.1234/test-new',
        ];

        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getSingleScalarResult')->willReturn(1);

        // Mock repositories
        $this->entityManager->expects($this->exactly(3))
            ->method('getRepository')
            ->willReturnCallback(function($class) use ($user, $doc, $qb) {
                if ($class === UserInformations::class) {
                    $repo = $this->userRepository;
                    $repo->method('find')->willReturn($user);
                    return $repo;
                }
                if ($class === Document::class) {
                    $repo = $this->documentRepository;
                    $repo->method('find')->willReturn($doc);
                    return $repo;
                }
                if ($class === PaperReferences::class) {
                    $repo = $this->refRepository;
                    $repo->method('createQueryBuilder')->willReturn($qb);
                    return $repo;
                }
            });

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $result = $this->service->addNewReference($form, $userInfo);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testAddNewReference_WithValidOpenAccessUrl_IsPersisted(): void
    {
        $persistedReference = $this->addNewReferenceWithOpenAccessUrl('https://oa.example.org/paper');

        $this->assertSame('https://oa.example.org/paper', $persistedReference['open-access']['url']);
        $this->assertSame('user', $persistedReference['open-access']['origin']);
    }

    /**
     * A javascript: scheme split with a tab bypasses a naive "doesn't start with javascript:"
     * blocklist (browsers strip tabs/newlines from a URL before parsing its scheme), so this
     * guards the fix rather than the original (bypassable) client-side regex.
     */
    #[Test]
    public function testAddNewReference_WithTabSplitJavascriptUrl_OpenAccessIsDropped(): void
    {
        $persistedReference = $this->addNewReferenceWithOpenAccessUrl("java\tscript:alert(1)");

        $this->assertArrayNotHasKey('open-access', $persistedReference);
    }

    /**
     * @return array<string, mixed>
     */
    private function addNewReferenceWithOpenAccessUrl(string $openAccessUrl): array
    {
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $doc = new Document();
        $doc->setId(123456);

        $form = [
            'id' => 123456,
            'addReference' => 'New test reference',
            'addReferenceOpenAccessUrl' => $openAccessUrl,
        ];

        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getSingleScalarResult')->willReturn(1);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($user, $doc, $qb) {
                if ($class === UserInformations::class) {
                    $repo = $this->userRepository;
                    $repo->method('find')->willReturn($user);
                    return $repo;
                }
                if ($class === Document::class) {
                    $repo = $this->documentRepository;
                    $repo->method('find')->willReturn($doc);
                    return $repo;
                }
                if ($class === PaperReferences::class) {
                    $repo = $this->refRepository;
                    $repo->method('createQueryBuilder')->willReturn($qb);
                    return $repo;
                }
                return null;
            });

        $persistedReference = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (PaperReferences $ref) use (&$persistedReference): bool {
                $persistedReference = $ref->getReference();
                return true;
            }));

        $result = $this->service->addNewReference($form, $userInfo);
        $this->assertTrue($result);

        return $persistedReference;
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testPersistOrderRef_UpdatesOrdering(): void
    {
        // Arrange
        $ref1 = new PaperReferences();
        $ref1->setId(5);
        $ref1->setReferenceOrder(999); // Old order

        $ref2 = new PaperReferences();
        $ref2->setId(2);
        $ref2->setReferenceOrder(999);

        $ref3 = new PaperReferences();
        $ref3->setId(8);
        $ref3->setReferenceOrder(999);

        // Mock repository to return refs
        $this->entityManager->expects($this->exactly(3))
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturnCallback(function() use ($ref1, $ref2, $ref3): PaperReferencesRepository {
                $repo = $this->refRepository;
                $repo->method('find')->willReturnOnConsecutiveCalls($ref1, $ref2, $ref3);
                return $repo;
            });

        $this->entityManager->expects($this->exactly(3))->method('persist');

        // Act
        $orderChanged = $this->service->persistOrderRef('5;2;8', 0);

        // Assert
        $this->assertEquals(3, $orderChanged);
        $this->assertEquals(0, $ref1->getReferenceOrder()); // ref5 → order 0
        $this->assertEquals(1, $ref2->getReferenceOrder()); // ref2 → order 1
        $this->assertEquals(2, $ref3->getReferenceOrder()); // ref8 → order 2
    }

    #[Test]
    public function testAutosaveReference_WithMissingUserInfoKeys_HandlesGracefully(): void
    {
        // Arrange
        $refId = 1;
        $ref = new PaperReferences();
        $this->refRepository->method('find')->willReturn($ref);
        
        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [PaperReferences::class, $this->refRepository],
                [UserInformations::class, $this->userRepository],
            ]);

        // Expect user to be persisted if new
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $userInfo = ['UID' => 1001]; // Missing FIRSTNAME, LASTNAME

        // Act
        $result = $this->service->autosaveReference($refId, '{}', 1, false, $userInfo);

        // Assert
        $this->assertIsArray($result);
        $this->assertNotNull($ref->getUid());
        $this->assertEquals(1001, $ref->getUid()->getId());
        $this->assertEquals('', $ref->getUid()->getSurname());
    }

    /**
     * The client-side check can be bypassed by calling /autosave directly, so this exercises
     * the server-side guard that must reject an unsafe scheme regardless.
     */
    #[Test]
    public function testAutosaveReference_WithMaliciousOpenAccessUrl_IsDropped(): void
    {
        // Arrange
        $refId = 1;
        $ref = new PaperReferences();
        $this->refRepository->method('find')->willReturn($ref);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [PaperReferences::class, $this->refRepository],
                [UserInformations::class, $this->userRepository],
            ]);

        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $referenceJson = json_encode([
            'raw_reference' => 'Some reference',
            'open-access' => ['url' => "java\tscript:alert(1)", 'source_title' => '', 'origin' => 'user', 'checked_at' => null],
        ]);

        // Act
        $result = $this->service->autosaveReference($refId, $referenceJson, 1, true, $userInfo);

        // Assert
        $this->assertArrayNotHasKey('open-access', $result);
        $this->assertArrayNotHasKey('open-access', $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithEmptyForm(): void
    {
        // Arrange
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $form = [
            // 'paperReferences' is missing
            'orderRef' => ''
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function($class) use ($user): MockObject {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            return $this->createMock(PaperReferencesRepository::class);
        });

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['referencePersisted']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_UserInfoMissingUid_ReturnsZeroCountsWithoutFlush(): void
    {
        // Arrange - resolveOrCreateUser() returns null when there's no user for the given UID
        // AND no UID at all is provided, exercising the early-return branch.
        $userInfo = [];
        $form = ['paperReferences' => [], 'orderRef' => ''];

        $this->userRepository->method('find')->willReturn(null);
        $this->entityManager->method('getRepository')
            ->with(UserInformations::class)
            ->willReturn($this->userRepository);

        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertSame(['orderPersisted' => 0, 'referencePersisted' => 0], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_ReferenceNotFound_IsSkipped(): void
    {
        // Arrange - the reference id sent by the client no longer exists in DB:
        // exercises the "continue" branch inside the loop.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $form = [
            'paperReferences' => [
                ['id' => 999, 'accepted' => 1, 'isDirtyTextAreaModifyRef' => '0'],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn(null);
                return $repo;
            }
            return null;
        });

        $this->entityManager->expects($this->never())->method('persist');

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertEquals(0, $result['referencePersisted']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_MissingAcceptedKey_IsSkipped(): void
    {
        // Arrange - the reference exists, but "accepted" is absent from the payload:
        // exercises the other half of the "continue" branch's condition.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(0);

        $form = [
            'paperReferences' => [
                ['id' => 1],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturnCallback(
                    fn (mixed $id): ?PaperReferences => $id === 1 ? $ref : null
                );
                return $repo;
            }
            return null;
        });

        $this->entityManager->expects($this->never())->method('persist');

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertEquals(0, $result['referencePersisted']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_SameAcceptedValue_ReportsNoChange(): void
    {
        // Arrange - the client resends the same "accepted" value: applyExplicitAcceptedValue()
        // must report 0 changes instead of always incrementing the counter.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);

        $form = [
            'paperReferences' => [
                ['id' => 1, 'accepted' => 1, 'isDirtyTextAreaModifyRef' => '0'],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert - the reference is still persisted (touched), but reported as unchanged
        $this->assertEquals(0, $result['referencePersisted']);
        $this->assertEquals(1, $ref->getAccepted());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_EmptyAcceptedOnNullState_InitializesToZero(): void
    {
        // Arrange - paperReference['accepted'] === '' with a null current state:
        // exercises applyDefaultAcceptedValue()'s "initialize" branch.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        // getAccepted() is null by default

        $form = [
            'paperReferences' => [
                ['id' => 1, 'accepted' => '', 'isDirtyTextAreaModifyRef' => '0'],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertEquals(1, $result['referencePersisted']);
        $this->assertEquals(0, $ref->getAccepted());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_EmptyAcceptedOnExistingState_ReportsNoChange(): void
    {
        // Arrange - applyDefaultAcceptedValue()'s "already set" branch: accepted is already
        // non-null, so re-sending an empty "accepted" value must not be reported as a change.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);

        $form = [
            'paperReferences' => [
                ['id' => 1, 'accepted' => '', 'isDirtyTextAreaModifyRef' => '0'],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $result = $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertEquals(0, $result['referencePersisted']);
        $this->assertEquals(1, $ref->getAccepted());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithArrayReferenceAndValidOpenAccessUrl_UpdatesReference(): void
    {
        // Arrange - paperReference['reference'] as an array with a valid open-access URL:
        // exercises normalizeReferenceInput()'s array branch and sanitizeOpenAccessUrl()'s
        // "valid URL" success branch (both previously untested).
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);

        $form = [
            'paperReferences' => [
                [
                    'id' => 1,
                    'accepted' => 1,
                    'isDirtyTextAreaModifyRef' => '0',
                    'reference' => [
                        'raw_reference' => 'Updated raw reference',
                        'open-access' => ['url' => 'https://oa.example.org/paper', 'source_title' => '', 'origin' => 'user', 'checked_at' => null],
                    ],
                ],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertSame('Updated raw reference', $ref->getReference()['raw_reference']);
        $this->assertSame('https://oa.example.org/paper', $ref->getReference()['open-access']['url']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithJsonStringReference_DecodesAndUpdatesReference(): void
    {
        // Arrange - paperReference['reference'] as a JSON string: exercises
        // normalizeReferenceInput()'s string-decoding branch.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);

        $form = [
            'paperReferences' => [
                [
                    'id' => 1,
                    'accepted' => 1,
                    'isDirtyTextAreaModifyRef' => '0',
                    'reference' => json_encode(['raw_reference' => 'From JSON string']),
                ],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertSame('From JSON string', $ref->getReference()['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithUnparseableStringReference_LeavesExistingReferenceUnchanged(): void
    {
        // Arrange - normalizeReferenceInput()'s "not valid JSON" returns null so setReference() is skipped
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $initialReference = ['raw_reference' => 'Original Ref Text', 'doi' => '10.1234/orig'];
        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);
        $ref->setReference($initialReference);

        $form = [
            'paperReferences' => [
                [
                    'id' => 1,
                    'accepted' => 1,
                    'isDirtyTextAreaModifyRef' => '0',
                    'reference' => 'not valid json',
                ],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            }
            return null;
        });

        // Act
        $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertSame($initialReference, $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAddNewReference_WithEmptyAddReference_ReturnsFalse(): void
    {
        // Arrange - exercises the "return false" branch when there's nothing to add
        $form = ['id' => 123456, 'addReference' => ''];

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $result = $this->service->addNewReference($form, ['UID' => 1001]);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAddNewReference_WhenUserNotFound_CreatesNewUser(): void
    {
        // Arrange - exercises the "is_null($user)" branch: no existing UserInformations
        $doc = new Document();
        $doc->setId(123456);

        $form = [
            'id' => 123456,
            'addReference' => 'A brand new reference',
        ];
        $userInfo = ['UID' => 4242, 'FIRSTNAME' => 'Ada', 'LASTNAME' => 'Lovelace'];

        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getSingleScalarResult')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($doc, $qb) {
                if ($class === UserInformations::class) {
                    $repo = $this->userRepository;
                    $repo->method('find')->willReturn(null);
                    return $repo;
                }
                if ($class === Document::class) {
                    $repo = $this->documentRepository;
                    $repo->method('find')->willReturn($doc);
                    return $repo;
                }
                if ($class === PaperReferences::class) {
                    $repo = $this->refRepository;
                    $repo->method('createQueryBuilder')->willReturn($qb);
                    return $repo;
                }
                return null;
            });

        $persistedRef = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (PaperReferences $ref) use (&$persistedRef): bool {
                $persistedRef = $ref;
                return true;
            }));

        // Act
        $result = $this->service->addNewReference($form, $userInfo);

        // Assert
        $this->assertTrue($result);
        $this->assertNotNull($persistedRef);
        $this->assertSame(4242, $persistedRef->getUid()->getId());
        $this->assertSame('Ada', $persistedRef->getUid()->getSurname());
        $this->assertSame('Lovelace', $persistedRef->getUid()->getName());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateChoicesReferencesByUser_WithNonArrayNonStringReference_LeavesExistingReferenceUnchanged(): void
    {
        // Arrange - normalizeReferenceInput()'s final fallback branch: "reference" is set
        // (so isset() is true) but is neither an array nor a string.
        $userInfo = ['UID' => 1001, 'FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'];
        $user = new UserInformations();
        $user->setId(1001);

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setAccepted(1);
        $ref->setReference(['raw_reference' => 'Original']);

        $form = [
            'paperReferences' => [
                ['id' => 1, 'accepted' => 1, 'isDirtyTextAreaModifyRef' => '0', 'reference' => 123],
            ],
            'orderRef' => '',
        ];

        $this->entityManager->method('getRepository')->willReturnCallback(function ($class) use ($user, $ref) {
            if ($class === UserInformations::class) {
                $repo = $this->userRepository;
                $repo->method('find')->willReturn($user);
                return $repo;
            }
            if ($class === PaperReferences::class) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturnCallback(
                    fn (mixed $id): ?PaperReferences => $id === 1 ? $ref : null
                );
                return $repo;
            }
            return null;
        });

        // Act
        $this->service->validateChoicesReferencesByUser($form, $userInfo);

        // Assert
        $this->assertSame(['raw_reference' => 'Original'], $ref->getReference());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferences_AcceptedType_UsesAcceptedRepositoryMethod(): void
    {
        // Arrange - the 'accepted' branch of the match() was never exercised
        $docId = 123456;

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setReference(['raw_reference' => 'Accepted ref']);
        $ref->setAccepted(1);
        $ref->setReferenceOrder(0);

        $this->grobid->expects($this->once())
            ->method('getAcceptedReferencesFromDB')
            ->with($docId)
            ->willReturn([$ref]);
        $this->grobid->expects($this->never())->method('getAllGrobidReferencesFromDB');

        $this->bibtex->method('getCslRefText')->willReturn(['raw_reference' => 'Accepted ref']);

        // Act
        $result = $this->service->getReferences($docId, 'accepted');

        // Assert
        $this->assertArrayHasKey(1, $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferences_ReferenceWithEmptyData_IsSkipped(): void
    {
        // Arrange - a reference whose stored data is empty must be skipped (continue branch)
        $docId = 123456;

        $emptyRef = new PaperReferences();
        $emptyRef->setId(1);
        $emptyRef->setReference([]);

        $validRef = new PaperReferences();
        $validRef->setId(2);
        $validRef->setReference(['raw_reference' => 'Valid ref']);
        $validRef->setAccepted(1);
        $validRef->setReferenceOrder(0);

        $this->grobid->expects($this->once())
            ->method('getAllGrobidReferencesFromDB')
            ->willReturn([$emptyRef, $validRef]);

        $this->bibtex->expects($this->once())
            ->method('getCslRefText')
            ->willReturn(['raw_reference' => 'Valid ref']);

        // Act
        $result = $this->service->getReferences($docId, 'all');

        // Assert
        $this->assertArrayNotHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferences_WithOpenAccessField_IncludesItInFormattedReference(): void
    {
        // Arrange - exercises the OPEN_ACCESS_REFERENCE_FIELDS branch
        $docId = 123456;

        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setReference([
            'raw_reference' => 'Ref with OA',
            'open-access' => ['url' => 'https://oa.example.org/paper', 'source_title' => 'Repo', 'origin' => 'openalex', 'checked_at' => null],
        ]);
        $ref->setAccepted(1);
        $ref->setReferenceOrder(0);

        $this->grobid->method('getAllGrobidReferencesFromDB')->willReturn([$ref]);
        $this->bibtex->method('getCslRefText')->willReturn(['raw_reference' => 'Ref with OA']);

        // Act
        $result = $this->service->getReferences($docId, 'all');

        // Assert
        $this->assertSame('https://oa.example.org/paper', $result[1]['ref']['open-access']['url']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetDocument_ReturnsDocumentFromRepository(): void
    {
        // Arrange
        $docId = 123456;
        $document = new Document();
        $document->setId($docId);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($this->documentRepository);
        $this->documentRepository->expects($this->once())
            ->method('find')
            ->with($docId)
            ->willReturn($document);

        // Act
        $result = $this->service->getDocument($docId);

        // Assert
        $this->assertSame($document, $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDocumentAlreadyExtracted_WhenDocumentExists_ReturnsTrue(): void
    {
        // Arrange
        $docId = 123456;
        $document = new Document();
        $document->setId($docId);

        $this->entityManager->method('getRepository')->with(Document::class)->willReturn($this->documentRepository);
        $this->documentRepository->method('find')->with($docId)->willReturn($document);

        // Act & Assert
        $this->assertTrue($this->service->documentAlreadyExtracted($docId));
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testDocumentAlreadyExtracted_WhenDocumentMissing_ReturnsFalse(): void
    {
        // Arrange
        $docId = 123456;

        $this->entityManager->method('getRepository')->with(Document::class)->willReturn($this->documentRepository);
        $this->documentRepository->method('find')->with($docId)->willReturn(null);

        // Act & Assert
        $this->assertFalse($this->service->documentAlreadyExtracted($docId));
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateDocumentId_PersistsAndReturnsNewDocument(): void
    {
        // Arrange
        $docId = 123456;

        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Document::class));
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $result = $this->service->createDocumentId($docId);

        // Assert
        $this->assertSame($docId, $result->getId());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAutosaveOrder_PersistsOrderAndFlushes(): void
    {
        // Arrange
        $ref = new PaperReferences();
        $ref->setId(1);
        $ref->setReferenceOrder(999);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturnCallback(function () use ($ref) {
                $repo = $this->refRepository;
                $repo->method('find')->willReturn($ref);
                return $repo;
            });

        $this->entityManager->expects($this->once())->method('persist')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $this->service->autosaveOrder('1');

        // Assert
        $this->assertEquals(0, $ref->getReferenceOrder());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAutosaveReference_ReferenceNotFound_ReturnsEmptyArray(): void
    {
        // Arrange - exercises the early "$ref === null" return branch
        $this->refRepository->method('find')->willReturn(null);
        $this->entityManager->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($this->refRepository);

        // Act
        $result = $this->service->autosaveReference(999, '{}', 1, false, ['UID' => 1001]);

        // Assert
        $this->assertSame([], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testAutosaveReference_NoUidInUserInfo_ReturnsEmptyArray(): void
    {
        // Arrange - exercises the "$user === null" fallback branch: no UID at all is provided
        $ref = new PaperReferences();
        $ref->setId(1);
        $this->refRepository->method('find')->willReturn($ref);

        $this->userRepository->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')->willReturnMap([
            [PaperReferences::class, $this->refRepository],
            [UserInformations::class, $this->userRepository],
        ]);

        // Act
        $result = $this->service->autosaveReference(1, '{}', 1, false, []);

        // Assert
        $this->assertSame([], $result);
    }

    #[Test]
    public function testAutosaveDeleteReference_WhenReferenceExistsAndDocumentMatches_DeletesAndFlushes(): void
    {
        // Arrange
        $docId = 100;
        $refId = 42;
        $doc = new Document();
        $doc->setId($docId);

        $ref = new PaperReferences();
        $ref->setDocument($doc);
        $this->refRepository->method('find')->with($refId)->willReturn($ref);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [PaperReferences::class, $this->refRepository],
            ]);

        $this->entityManager->expects($this->once())->method('remove')->with($ref);
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $result = $this->service->autosaveDeleteReference($refId, $docId);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testAutosaveDeleteReference_WhenReferenceBelongsToDifferentDocument_ReturnsFalse(): void
    {
        // Arrange
        $expectedDocId = 100;
        $differentDocId = 200;
        $refId = 42;

        $doc = new Document();
        $doc->setId($differentDocId);

        $ref = new PaperReferences();
        $ref->setDocument($doc);
        $this->refRepository->method('find')->with($refId)->willReturn($ref);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [PaperReferences::class, $this->refRepository],
            ]);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $result = $this->service->autosaveDeleteReference($refId, $expectedDocId);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testAutosaveDeleteReference_WhenReferenceNotFound_ReturnsFalse(): void
    {
        // Arrange
        $docId = 100;
        $refId = 999;
        $this->refRepository->method('find')->with($refId)->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [PaperReferences::class, $this->refRepository],
            ]);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        // Act
        $result = $this->service->autosaveDeleteReference($refId, $docId);

        // Assert
        $this->assertFalse($result);
    }
}
