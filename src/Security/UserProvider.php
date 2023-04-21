<?php
namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use \Symfony\Component\Security\Core\User\UserProviderInterface;


class UserProvider implements UserProviderInterface {


    public function refreshUser(UserInterface $user): User
    {
        // TODO: Implement refreshUser() method.
        if (!$user instanceof User) {
            throw new UnsupportedUserException(
                sprintf('Instances of "%s" are not supported.', get_class($user))
            );
        }
        return $this->loadUserByUsername($user->getUsername());
    }

    public function supportsClass(string $class): bool
    {
        // TODO: Implement supportsClass() method.
        return $class === User::class;
    }

    public function loadUserByUsername(string $username): User
    {
        // TODO: Implement loadUserByUsername() method.
        $user = new User();
        $user->setUsername($username);
        return $user;
    }
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = new User();
        $user->setUsername($identifier);
        return $user;
    }
}