<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use PHPUnit\Framework\MockObject\MockObject;
use App\Repository\PaperReferencesRepository;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Repository\DocumentRepository;
use App\Services\OpenAccess\OpenAccessReferenceEnricher;
use App\Services\SolrReferenceEnricher;
use App\Services\Tei;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TeiTest extends TestCase
{
    private Tei $service;
    private MockObject $entityManager;
    private MockObject $documentRepository;
    private MockObject $solrReferenceEnricher;
    private MockObject $openAccessReferenceEnricher;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->solrReferenceEnricher = $this->createMock(SolrReferenceEnricher::class);
        $this->solrReferenceEnricher->method('enrichReferences')->willReturnArgument(0);
        $this->openAccessReferenceEnricher = $this->createMock(OpenAccessReferenceEnricher::class);
        $this->openAccessReferenceEnricher->method('enrichReferences')->willReturnArgument(0);

        $this->service = new Tei(
            $this->entityManager,
            $this->documentRepository,
            $this->solrReferenceEnricher,
            $this->openAccessReferenceEnricher
        );
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferencesInTei_ValidTei_ExtractsReferences(): void
    {
        // Arrange - Use the TEI sample file
        $teiXml = file_get_contents(__DIR__ . '/../../Fixtures/grobid_tei_sample.xml');

        // Act
        $result = $this->service->getReferencesInTei($teiXml);

        // Assert
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result), 'Should extract at least one reference');

        // Each element is now a flat associative array (not a JSON string)
        $firstRef = $result[0];
        $this->assertIsArray($firstRef);
        $this->assertArrayHasKey('raw_reference', $firstRef);
        $this->assertNotEmpty($firstRef['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
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
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferencesInTei_MalformedXml_ReturnsEmptyArray(): void
    {
        // Arrange - genuinely malformed XML (simplexml_load_string() returns false),
        // exercising the "$xml === false" early-return branch (as opposed to the
        // well-formed-but-non-matching XML used by the other "invalid" test above).
        $malformedXml = '<broken><unclosed>';

        libxml_use_internal_errors(true);

        // Act
        $result = $this->service->getReferencesInTei($malformedXml);

        libxml_clear_errors();

        // Assert
        $this->assertSame([], $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetReferencesInTei_WithDoiInAnalytic_ExtractsDoi(): void
    {
        // Arrange - DOI nested under <analytic>, matching GROBID's consolidated-citation
        // output and the exact path read by Tei::extractDoi().
        $teiXml = <<<'XML'
<TEI xmlns="http://www.tei-c.org/ns/1.0">
    <text xml:lang="en">
        <back>
            <div type="references">
                <listBibl>
                    <biblStruct xml:id="b0">
                        <analytic>
                            <title level="a" type="main">Reference with DOI</title>
                            <idno type="DOI">10.1234/analytic-doi</idno>
                        </analytic>
                        <note type="raw_reference">Some raw reference text.</note>
                    </biblStruct>
                </listBibl>
            </div>
        </back>
    </text>
</TEI>
XML;

        // Act
        $result = $this->service->getReferencesInTei($teiXml);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('10.1234/analytic-doi', $result[0]['doi']);
        $this->assertSame('Some raw reference text.', $result[0]['raw_reference']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertReferencesInDB_NewDocument_CreatesDocumentAndReferences(): void
    {
        // Arrange — references are now flat arrays, not JSON strings
        $docId = 123456;
        $references = [
            ['raw_reference' => 'Test reference 1', 'doi' => '10.1234/test1'],
            ['raw_reference' => 'Test reference 2'],
        ];
        $source = PaperReferences::SOURCE_METADATA_GROBID;

        // Mock: document does not exist
        $this->documentRepository->expects($this->once())
            ->method('find')
            ->with($docId)
            ->willReturn(null);

        // Mock repository for removeAllRefGrobidSource
        $refRepo = $this->createMock(PaperReferencesRepository::class);
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
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertReferencesInDB_ExistingDocument_PreservesAcceptedReferences(): void
    {
        // Arrange — references are now flat arrays
        $docId = 123456;
        $newReferences = [
            ['raw_reference' => 'New reference 1'],
        ];
        $source = PaperReferences::SOURCE_METADATA_GROBID;

        // Create existing document with an accepted reference
        $existingDoc = new Document();
        $existingDoc->setId($docId);

        $acceptedRef = new PaperReferences();
        $acceptedRef->setId(1);
        $acceptedRef->setReference(['raw_reference' => 'Accepted reference']);
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
        $refRepo = $this->createMock(PaperReferencesRepository::class);
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

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testInsertReferencesInDB_RemovesUnacceptedAndNullAcceptedExistingReferences(): void
    {
        // Arrange - removeAllRefGrobidSource() must remove any existing reference whose
        // accepted state is 0 or null (i.e. never validated by a user), and keep accepted ones.
        $docId = 123456;
        $newReferences = [
            ['raw_reference' => 'New reference 1'],
        ];
        $source = PaperReferences::SOURCE_METADATA_GROBID;

        $unacceptedRef = new PaperReferences();
        $unacceptedRef->setId(1);
        $unacceptedRef->setReference(['raw_reference' => 'Unaccepted reference']);
        $unacceptedRef->setAccepted(0);

        $nullAcceptedRef = new PaperReferences();
        $nullAcceptedRef->setId(2);
        $nullAcceptedRef->setReference(['raw_reference' => 'Null-accepted reference']);
        $nullAcceptedRef->setAccepted(null);

        $this->documentRepository->expects($this->once())
            ->method('find')
            ->with($docId)
            ->willReturn(null);

        $refRepo = $this->createMock(PaperReferencesRepository::class);
        $refRepo->method('findBy')->willReturn([$unacceptedRef, $nullAcceptedRef]);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(PaperReferences::class)
            ->willReturn($refRepo);

        // Both stale references must be removed
        $this->entityManager->expects($this->exactly(2))
            ->method('remove')
            ->with($this->logicalOr($unacceptedRef, $nullAcceptedRef));

        // Act
        $this->service->insertReferencesInDB($newReferences, $docId, $source);

        // Assert - verified via the remove() expectations above
        $this->assertTrue(true);
    }
}
