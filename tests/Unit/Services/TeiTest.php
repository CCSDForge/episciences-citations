<?php

namespace App\Tests\Unit\Services;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use App\Services\Tei;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TeiTest extends TestCase
{
    private Tei $service;
    private EntityManagerInterface $entityManager;
    private DocumentRepository $documentRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        $this->service = new Tei(
            $this->entityManager,
            $this->documentRepository
        );
    }

    #[Test]
    public function testGetReferencesInTei_ValidTei_ExtractsReferences(): void
    {
        // Arrange - Use the TEI sample file
        $teiXml = file_get_contents(__DIR__ . '/../../Fixtures/grobid_tei_sample.xml');

        // Act
        $result = $this->service->getReferencesInTei($teiXml);

        // Assert
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result), 'Should extract at least one reference');

        // Verify structure of the first reference
        $firstRef = json_decode($result[0], true);
        $this->assertArrayHasKey('raw_reference', $firstRef);
        $this->assertNotEmpty($firstRef['raw_reference']);
    }

    #[Test]
    public function testGetReferencesInTei_InvalidXml_ReturnsEmpty(): void
    {
        // Arrange - Invalid XML
        $invalidXml = '<invalid>not a TEI document</invalid>';

        // Act
        $result = $this->service->getReferencesInTei($invalidXml);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result, 'Should return empty array for invalid XML');
    }

    #[Test]
    public function testInsertReferencesInDB_NewDocument_CreatesDocumentAndReferences(): void
    {
        // Arrange
        $docId = 123456;
        $references = [
            json_encode(['raw_reference' => 'Test reference 1', 'doi' => '10.1234/test1']),
            json_encode(['raw_reference' => 'Test reference 2'])
        ];
        $source = PaperReferences::SOURCE_METADATA_GROBID;

        // Mock: document does not exist
        $this->documentRepository->expects($this->once())
            ->method('find')
            ->with($docId)
            ->willReturn(null);

        // Mock repository for removeAllRefGrobidSource
        $refRepo = $this->createMock(\App\Repository\PaperReferencesRepository::class);
        $refRepo->method('findBy')->willReturn([]);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($refRepo);

        // Expect persist called 2 times (2 references)
        $this->entityManager->expects($this->exactly(2))
            ->method('persist');

        // Expect flush called 2 times (removeAll + insert)
        $this->entityManager->expects($this->exactly(2))
            ->method('flush');

        // Act
        $this->service->insertReferencesInDB($references, $docId, $source);

        // Assert - verified via expectations
        $this->assertTrue(true);
    }

    #[Test]
    public function testInsertReferencesInDB_ExistingDocument_PreservesAcceptedReferences(): void
    {
        // Arrange
        $docId = 123456;
        $newReferences = [
            json_encode(['raw_reference' => 'New reference 1'])
        ];
        $source = PaperReferences::SOURCE_METADATA_GROBID;

        // Create existing document with an accepted reference
        $existingDoc = new Document();
        $existingDoc->setId($docId);

        $acceptedRef = new PaperReferences();
        $acceptedRef->setId(1);
        $acceptedRef->setReference([json_encode(['raw_reference' => 'Accepted reference'])]);
        $acceptedRef->setAccepted(1);
        $acceptedRef->setReferenceOrder(0);
        $acceptedRef->setDocument($existingDoc);

        $existingDoc->addPaperReference($acceptedRef);

        // Mock: document exists
        $this->documentRepository->expects($this->once())
            ->method('find')
            ->with($docId)
            ->willReturn($existingDoc);

        // Mock repository for removeAllRefGrobidSource
        $refRepo = $this->createMock(\App\Repository\PaperReferencesRepository::class);
        $refRepo->method('findBy')->willReturn([$acceptedRef]);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($refRepo);

        // Expect persist for accepted reference (reordering) + new reference
        $this->entityManager->expects($this->atLeast(2))
            ->method('persist');

        // Expect flush (3 times: removeAll + reordering + insert)
        $this->entityManager->expects($this->exactly(3))
            ->method('flush');

        // Expect remove NOT to be called (accepted reference)
        $this->entityManager->expects($this->never())
            ->method('remove');

        // Act
        $this->service->insertReferencesInDB($newReferences, $docId, $source);

        // Assert - accepted reference preserved + new reference added
        $this->assertCount(2, $existingDoc->getPaperReferences());
    }
}
