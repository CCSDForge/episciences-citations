<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;


class User implements UserInterface, EquatableInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $uid;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $username;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        // TODO: Implement isEqualTo() method.
        return $user->getUserIdentifier() === $this->getUid();
    }

    public function  getRoles(): array
    {
        // TODO: Implement getRoles() method.

        return ($this->username === '__NO_USER__') ? ['ROLE_ANO'] :  ['ROLE_USER'] ;

    }

    public function getPassword()
    {
        // TODO: Implement getPassword() method.
    }

    public function getSalt()
    {
        // TODO: Implement getSalt() method.
    }

    public function eraseCredentials()
    {
        // TODO: Implement eraseCredentials() method.
    }
//    public function getUserIdentifier(): string {
//    }

    /**
     * Returns the identifier for this user (e.g. username or email address).
     */
    public function getUserIdentifier(): string
    {
        // TODO: Implement getUserIdentifier() method.
        return $this->getUid() ?? '';
    }
}
