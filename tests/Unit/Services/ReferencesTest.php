<?php

namespace App\Tests\Unit\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Repository\PaperReferencesRepository;
use App\Repository\UserInformationsRepository;
use App\Services\Bibtex;
use App\Services\Grobid;
use App\Services\References;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReferencesTest extends TestCase
{
    private References $service;
    private EntityManagerInterface $entityManager;
    private Grobid $grobid;
    private Bibtex $bibtex;
    private PaperReferencesRepository $refRepository;
    private UserInformationsRepository $userRepository;
    private DocumentRepository $documentRepository;

    protected function setUp(): void
    {
        // Mock EntityManager
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->grobid = $this->createMock(Grobid::class);
        $this->bibtex = $this->createMock(Bibtex::class);

        // Mock repositories
        $this->refRepository = $this->createMock(PaperReferencesRepository::class);
        $this->userRepository = $this->createMock(UserInformationsRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        // Service under test
        $this->service = new References(
            $this->entityManager,
            $this->grobid,
            $this->bibtex
        );
    }

    #[Test]
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
    public function testGetReferences_AllType_ReturnsFormatted(): void
    {
        // Arrange
        $docId = 123456;

        $ref1 = new PaperReferences();
        $ref1->setId(1);
        $ref1->setReference([json_encode([
            'raw_reference' => 'Test ref 1',
            'csl' => ['type' => 'article', 'title' => 'Test']
        ])]);
        $ref1->setAccepted(1);
        $ref1->setReferenceOrder(0);

        $ref2 = new PaperReferences();
        $ref2->setId(2);
        $ref2->setReference([json_encode(['raw_reference' => 'Test ref 2'])]);
        $ref2->setAccepted(0);
        $ref2->setReferenceOrder(1);

        // Mock Grobid service
        $this->grobid->expects($this->once())
            ->method('getAllGrobidReferencesFromDB')
            ->with($docId)
            ->willReturn([$ref1, $ref2]);

        // Mock Bibtex formatting
        $this->bibtex->expects($this->exactly(2))
            ->method('getCslRefText')
            ->willReturn('Formatted reference text');

        // Act
        $result = $this->service->getReferences($docId, 'all');

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertEquals('Formatted reference text', $result[1]['ref']);
        $this->assertEquals(1, $result[1]['isAccepted']);
        $this->assertEquals(0, $result[1]['referenceOrder']);
        $this->assertArrayHasKey('csl', $result[1]); // CSL present
        $this->assertArrayNotHasKey('csl', $result[2]); // No CSL
    }

    #[Test]
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
            'paperReferences' => [
                ['reference_order' => 0],
                ['reference_order' => 1]
            ]
        ];

        // Mock repositories
        $this->entityManager->expects($this->exactly(2))
            ->method('getRepository')
            ->willReturnCallback(function($class) use ($user, $doc) {
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
            });

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Act
        $result = $this->service->addNewReference($form, $userInfo);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
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
            ->willReturnCallback(function() use ($ref1, $ref2, $ref3) {
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
}
