<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaperReferences entity
 *
 * Tests validate entity behavior including:
 * - Getters and setters
 * - Source validation
 * - Relationships with Document and UserInformations
 * - Reference data structure
 */
class PaperReferencesTest extends TestCase
{
    private PaperReferences $entity;

    protected function setUp(): void
    {
        $this->entity = new PaperReferences();
    }

    #[Test]
    public function testConstants_SourceTypes_AreDefined(): void
    {
        // Assert - verify all source type constants exist
        $this->assertEquals('GROBID', PaperReferences::SOURCE_METADATA_GROBID);
        $this->assertEquals('USER', PaperReferences::SOURCE_METADATA_EPI_USER);
        $this->assertEquals('BIBTEX', PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);
        $this->assertEquals('SEMANTICS', PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
    }

    #[Test]
    public function testSetId_ValidId_SetsValue(): void
    {
        // Act
        $this->entity->setId(123);

        // Assert
        $this->assertEquals(123, $this->entity->getId());
    }

    #[Test]
    public function testSetSource_ValidGrobidSource_SetsValue(): void
    {
        // Act
        $result = $this->entity->setSource(PaperReferences::SOURCE_METADATA_GROBID);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertEquals(PaperReferences::SOURCE_METADATA_GROBID, $this->entity->getSource());
    }

    #[Test]
    public function testSetSource_ValidUserSource_SetsValue(): void
    {
        // Act
        $this->entity->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);

        // Assert
        $this->assertEquals(PaperReferences::SOURCE_METADATA_EPI_USER, $this->entity->getSource());
    }

    #[Test]
    public function testSetSource_ValidBibtexSource_SetsValue(): void
    {
        // Act
        $this->entity->setSource(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);

        // Assert
        $this->assertEquals(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT, $this->entity->getSource());
    }

    #[Test]
    public function testSetSource_ValidSemanticsSource_SetsValue(): void
    {
        // Act
        $this->entity->setSource(PaperReferences::SOURCE_SEMANTICS_SCHOLAR);

        // Assert
        $this->assertEquals(PaperReferences::SOURCE_SEMANTICS_SCHOLAR, $this->entity->getSource());
    }

    #[Test]
    public function testSetSource_InvalidSource_ThrowsException(): void
    {
        // Assert - expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status');

        // Act - attempt to set invalid source
        $this->entity->setSource('INVALID_SOURCE');
    }

    #[Test]
    public function testSetUpdatedAt_ValidDateTime_SetsValue(): void
    {
        // Arrange
        $dateTime = new \DateTimeImmutable('2024-01-15 10:30:00');

        // Act
        $result = $this->entity->setUpdatedAt($dateTime);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertEquals($dateTime, $this->entity->getUpdatedAt());
    }

    #[Test]
    public function testSetReference_ValidArray_SetsValue(): void
    {
        // Arrange
        $reference = [
            json_encode([
                'raw_reference' => 'Smith, J. (2020). Test Article. Journal, 10(2), 123-145.',
                'doi' => '10.1234/test'
            ])
        ];

        // Act
        $result = $this->entity->setReference($reference);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertEquals($reference, $this->entity->getReference());
    }

    #[Test]
    public function testSetReference_EmptyArray_SetsValue(): void
    {
        // Act
        $this->entity->setReference([]);

        // Assert
        $this->assertEquals([], $this->entity->getReference());
    }

    #[Test]
    public function testSetReferenceOrder_ValidOrder_SetsValue(): void
    {
        // Act
        $result = $this->entity->setReferenceOrder(5);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertEquals(5, $this->entity->getReferenceOrder());
    }

    #[Test]
    public function testSetAccepted_AcceptedValue_SetsValue(): void
    {
        // Act
        $result = $this->entity->setAccepted(1);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertEquals(1, $this->entity->getAccepted());
    }

    #[Test]
    public function testSetAccepted_RejectedValue_SetsValue(): void
    {
        // Act
        $this->entity->setAccepted(0);

        // Assert
        $this->assertEquals(0, $this->entity->getAccepted());
    }

    #[Test]
    public function testSetAccepted_NullValue_SetsValue(): void
    {
        // Act
        $this->entity->setAccepted(null);

        // Assert
        $this->assertNull($this->entity->getAccepted());
    }

    #[Test]
    public function testSetDocument_ValidDocument_SetsValue(): void
    {
        // Arrange
        $document = new Document();
        $document->setId(123456);

        // Act
        $result = $this->entity->setDocument($document);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertSame($document, $this->entity->getDocument());
    }

    #[Test]
    public function testSetDocument_NullValue_SetsValue(): void
    {
        // Act
        $this->entity->setDocument(null);

        // Assert
        $this->assertNull($this->entity->getDocument());
    }

    #[Test]
    public function testSetUid_ValidUserInformations_SetsValue(): void
    {
        // Arrange
        $user = new UserInformations();
        $user->setId(1);
        $user->setName('John');
        $user->setSurname('Doe');

        // Act
        $result = $this->entity->setUid($user);

        // Assert
        $this->assertSame($this->entity, $result, 'Should return $this for fluent interface');
        $this->assertSame($user, $this->entity->getUid());
    }

    #[Test]
    public function testSetUid_NullValue_SetsValue(): void
    {
        // Act
        $this->entity->setUid(null);

        // Assert
        $this->assertNull($this->entity->getUid());
    }

    #[Test]
    public function testFluentInterface_ChainedSetters_WorksCorrectly(): void
    {
        // Arrange
        $document = new Document();
        $document->setId(123);
        $dateTime = new \DateTimeImmutable();

        // Act - chain multiple setters
        $result = $this->entity
            ->setSource(PaperReferences::SOURCE_METADATA_GROBID)
            ->setReferenceOrder(0)
            ->setAccepted(1)
            ->setDocument($document)
            ->setUpdatedAt($dateTime);

        // Assert - verify fluent interface
        $this->assertSame($this->entity, $result);
        $this->assertEquals(PaperReferences::SOURCE_METADATA_GROBID, $this->entity->getSource());
        $this->assertEquals(0, $this->entity->getReferenceOrder());
        $this->assertEquals(1, $this->entity->getAccepted());
        $this->assertSame($document, $this->entity->getDocument());
        $this->assertEquals($dateTime, $this->entity->getUpdatedAt());
    }

    #[Test]
    public function testInitialState_NewEntity_HasNullValues(): void
    {
        // Arrange - create new entity
        $entity = new PaperReferences();

        // Assert - verify initial state
        $this->assertNull($entity->getId());
        $this->assertNull($entity->getAccepted());
        $this->assertNull($entity->getDocument());
        $this->assertNull($entity->getUid());
        $this->assertNull($entity->getUpdatedAt());
        $this->assertNull($entity->getReferenceOrder());
        $this->assertEquals([], $entity->getReference());
    }
}
