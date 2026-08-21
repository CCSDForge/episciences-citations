<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProviderTest extends TestCase
{
    #[Test]
    public function testLoadUserByIdentifierReturnsUserWithGivenUsername(): void
    {
        $provider = new UserProvider();

        $user = $provider->loadUserByIdentifier('jdoe');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('jdoe', $user->getUsername());
    }

    #[Test]
    public function testLoadUserByUsernameReturnsUserWithGivenUsername(): void
    {
        $provider = new UserProvider();

        $user = $provider->loadUserByUsername('jdoe');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('jdoe', $user->getUsername());
    }

    #[Test]
    public function testSupportsClassReturnsTrueForUserClass(): void
    {
        $provider = new UserProvider();

        $this->assertTrue($provider->supportsClass(User::class));
    }

    #[Test]
    public function testSupportsClassReturnsFalseForOtherClasses(): void
    {
        $provider = new UserProvider();

        $this->assertFalse($provider->supportsClass(\stdClass::class));
    }

    #[Test]
    public function testRefreshUserReturnsFreshUserWithSameUsername(): void
    {
        $provider = new UserProvider();
        $original = new User();
        $original->setUsername('jdoe');

        $refreshed = $provider->refreshUser($original);

        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertNotSame($original, $refreshed);
        $this->assertSame('jdoe', $refreshed->getUsername());
    }

    #[Test]
    public function testRefreshUserThrowsForUnsupportedUserImplementation(): void
    {
        $provider = new UserProvider();
        $unsupportedUser = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'unsupported';
            }
        };

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser($unsupportedUser);
    }
}
