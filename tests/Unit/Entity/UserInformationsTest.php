<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserInformationsTest extends TestCase
{
    private UserInformations $userInformations;

    protected function setUp(): void
    {
        $this->userInformations = new UserInformations();
    }

    #[Test]
    public function testInitialState_NewEntity_HasNullValuesAndEmptyReferences(): void
    {
        $this->assertNull($this->userInformations->getId());
        $this->assertNull($this->userInformations->getName());
        $this->assertNull($this->userInformations->getSurname());
        $this->assertInstanceOf(Collection::class, $this->userInformations->getPaperReferences());
        $this->assertCount(0, $this->userInformations->getPaperReferences());
    }

    #[Test]
    public function testSetId_ValidId_SetsValueAndReturnsSelf(): void
    {
        $result = $this->userInformations->setId(7);

        $this->assertSame($this->userInformations, $result, 'Should return $this for fluent interface');
        $this->assertSame(7, $this->userInformations->getId());
    }

    #[Test]
    public function testSetId_Null_SetsNull(): void
    {
        $this->userInformations->setId(7);

        $this->userInformations->setId(null);

        $this->assertNull($this->userInformations->getId());
    }

    #[Test]
    public function testSetName_ValidName_SetsValueAndReturnsSelf(): void
    {
        $result = $this->userInformations->setName('John');

        $this->assertSame($this->userInformations, $result, 'Should return $this for fluent interface');
        $this->assertSame('John', $this->userInformations->getName());
    }

    #[Test]
    public function testSetName_Null_SetsNull(): void
    {
        $this->userInformations->setName('John');

        $this->userInformations->setName(null);

        $this->assertNull($this->userInformations->getName());
    }

    #[Test]
    public function testSetSurname_ValidSurname_SetsValueAndReturnsSelf(): void
    {
        $result = $this->userInformations->setSurname('Doe');

        $this->assertSame($this->userInformations, $result, 'Should return $this for fluent interface');
        $this->assertSame('Doe', $this->userInformations->getSurname());
    }

    #[Test]
    public function testSetSurname_Null_SetsNull(): void
    {
        $this->userInformations->setSurname('Doe');

        $this->userInformations->setSurname(null);

        $this->assertNull($this->userInformations->getSurname());
    }

    #[Test]
    public function testAddPaperReferences_NewReference_AddsToCollection(): void
    {
        $reference = new PaperReferences();

        $result = $this->userInformations->addPaperReferences($reference);

        $this->assertSame($this->userInformations, $result, 'Should return $this for fluent interface');
        $this->assertTrue($this->userInformations->getPaperReferences()->contains($reference));
        $this->assertCount(1, $this->userInformations->getPaperReferences());
    }

    #[Test]
    public function testAddPaperReferences_AlreadyPresentReference_DoesNotAddTwice(): void
    {
        $reference = new PaperReferences();
        $this->userInformations->addPaperReferences($reference);

        $this->userInformations->addPaperReferences($reference);

        $this->assertCount(1, $this->userInformations->getPaperReferences());
    }
}
