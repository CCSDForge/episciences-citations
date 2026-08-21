<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for the User entity.
 *
 * Roles are derived from the username, not stored: getRoles() returns
 * ROLE_ANO for the sentinel '__NO_USER__' username and ROLE_USER for any
 * other username. setRoles() is an intentional no-op stub required by
 * UserInterface — it does not persist anything, by design of the CAS-based
 * authentication model where roles are always derived.
 */
class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function testImplementsUserInterface(): void
    {
        $this->assertInstanceOf(UserInterface::class, $this->user);
    }

    #[Test]
    public function testInitialState_NewUser_HasNullValues(): void
    {
        $this->assertNull($this->user->getId());
        $this->assertNull($this->user->getEmail());
        $this->assertNull($this->user->getUsername());
        $this->assertNull($this->user->getUid());
    }

    #[Test]
    public function testGetUserIdentifier_NoUsernameSet_ReturnsEmptyString(): void
    {
        $this->assertSame('', $this->user->getUserIdentifier());
    }

    #[Test]
    public function testGetUserIdentifier_UsernameSet_ReturnsUsername(): void
    {
        $this->user->setUsername('jdoe');

        $this->assertSame('jdoe', $this->user->getUserIdentifier());
    }

    #[Test]
    public function testSetEmail_ValidEmail_SetsValueAndReturnsSelf(): void
    {
        $result = $this->user->setEmail('jdoe@example.com');

        $this->assertSame($this->user, $result);
        $this->assertSame('jdoe@example.com', $this->user->getEmail());
    }

    #[Test]
    public function testSetUsername_ValidUsername_SetsValue(): void
    {
        $this->user->setUsername('jdoe');

        $this->assertSame('jdoe', $this->user->getUsername());
    }

    #[Test]
    public function testSetUsername_Null_SetsNull(): void
    {
        $this->user->setUsername('jdoe');
        $this->user->setUsername(null);

        $this->assertNull($this->user->getUsername());
    }

    #[Test]
    public function testGetRoles_SentinelNoUserUsername_ReturnsRoleAno(): void
    {
        $this->user->setUsername('__NO_USER__');

        $this->assertSame(['ROLE_ANO'], $this->user->getRoles());
    }

    #[Test]
    public function testGetRoles_RegularUsername_ReturnsRoleUser(): void
    {
        $this->user->setUsername('jdoe');

        $this->assertSame(['ROLE_USER'], $this->user->getRoles());
    }

    #[Test]
    public function testGetRoles_NoUsernameSet_ReturnsRoleUser(): void
    {
        // username is null, which is not the '__NO_USER__' sentinel, so ROLE_USER
        $this->assertSame(['ROLE_USER'], $this->user->getRoles());
    }

    #[Test]
    public function testSetRoles_IsNoOpStub_DoesNotChangeDerivedRoles(): void
    {
        // Intentional: setRoles() is a stub required by UserInterface. Roles are
        // always derived from the username by getRoles(), never stored/settable.
        $this->user->setUsername('jdoe');

        $result = $this->user->setRoles(['ROLE_ADMIN']);

        $this->assertSame($this->user, $result, 'setRoles should return $this for fluent interface');
        $this->assertSame(['ROLE_USER'], $this->user->getRoles(), 'roles remain derived from username, unaffected by setRoles');
    }

    #[Test]
    public function testGetSalt_ReturnsNull(): void
    {
        $this->assertNull($this->user->getSalt());
    }

    #[Test]
    public function testEraseCredentials_DoesNotThrow(): void
    {
        $this->user->eraseCredentials();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function testGetUid_NoUidSet_ReturnsNull(): void
    {
        $this->assertNull($this->user->getUid());
    }

    #[Test]
    public function testSetUid_StringValue_SetsValue(): void
    {
        $this->user->setUid('some-uid');

        $this->assertSame('some-uid', $this->user->getUid());
    }

    #[Test]
    public function testSetUid_IntValue_SetsValue(): void
    {
        $this->user->setUid(42);

        $this->assertSame(42, $this->user->getUid());
    }

    #[Test]
    public function testSetUid_NullValue_SetsValue(): void
    {
        $this->user->setUid('some-uid');
        $this->user->setUid(null);

        $this->assertNull($this->user->getUid());
    }
}
