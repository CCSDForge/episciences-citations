<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\PaperReferences;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    private Document $document;

    protected function setUp(): void
    {
        $this->document = new Document();
    }

    #[Test]
    public function testInitialState_NewDocument_HasNullIdAndEmptyReferences(): void
    {
        $this->assertNull($this->document->getId());
        $this->assertInstanceOf(Collection::class, $this->document->getPaperReferences());
        $this->assertCount(0, $this->document->getPaperReferences());
    }

    #[Test]
    public function testSetId_ValidId_SetsValueAndReturnsSelf(): void
    {
        $result = $this->document->setId(42);

        $this->assertSame($this->document, $result, 'Should return $this for fluent interface');
        $this->assertSame(42, $this->document->getId());
    }

    #[Test]
    public function testAddPaperReference_NewReference_AddsAndSetsOwningSide(): void
    {
        $reference = new PaperReferences();

        $result = $this->document->addPaperReference($reference);

        $this->assertSame($this->document, $result, 'Should return $this for fluent interface');
        $this->assertTrue($this->document->getPaperReferences()->contains($reference));
        $this->assertSame($this->document, $reference->getDocument(), 'Owning side should be set to the document');
        $this->assertCount(1, $this->document->getPaperReferences());
    }

    #[Test]
    public function testAddPaperReference_AlreadyPresentReference_DoesNotAddTwice(): void
    {
        $reference = new PaperReferences();
        $this->document->addPaperReference($reference);

        $this->document->addPaperReference($reference);

        $this->assertCount(1, $this->document->getPaperReferences());
    }

    #[Test]
    public function testRemovePaperReference_PresentReference_RemovesAndClearsOwningSide(): void
    {
        $reference = new PaperReferences();
        $this->document->addPaperReference($reference);

        $result = $this->document->removePaperReference($reference);

        $this->assertSame($this->document, $result, 'Should return $this for fluent interface');
        $this->assertFalse($this->document->getPaperReferences()->contains($reference));
        $this->assertNull($reference->getDocument(), 'Owning side should be cleared when removed');
    }

    #[Test]
    public function testRemovePaperReference_NotPresentReference_DoesNothing(): void
    {
        $reference = new PaperReferences();

        $result = $this->document->removePaperReference($reference);

        $this->assertSame($this->document, $result);
        $this->assertCount(0, $this->document->getPaperReferences());
    }

    #[Test]
    public function testRemovePaperReference_ReferenceOwnedByAnotherDocument_DoesNotClearOwningSide(): void
    {
        // Reference is in this document's collection but its owning "document" was
        // reassigned to another Document instance in the meantime.
        $otherDocument = new Document();
        $reference = new PaperReferences();
        $this->document->addPaperReference($reference);
        $reference->setDocument($otherDocument);

        $this->document->removePaperReference($reference);

        $this->assertSame($otherDocument, $reference->getDocument(), 'Owning side should remain untouched since it no longer points to this document');
    }
}
