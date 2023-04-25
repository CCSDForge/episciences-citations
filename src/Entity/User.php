<?php
namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface
{

    private $id;

    private $username;
    private $email;
    private $uid;

    private $roles = [];

    public function getId(): ?int
    {
    return $this->id;
    }

    public function getEmail(): ?string
    {
    return $this->email;
    }

    public function setEmail(string $email): self
    {
    $this->email = $email;

    return $this;
    }

    /**
    * The public representation of the user (e.g. a username, an email address, etc.)
    *
    * @see UserInterface
    */
    public function getUserIdentifier(): string
    {
    return (string) $this->username;
    }

    /**
    * @see UserInterface
    */
    public function getRoles(): array
    {
    $roles = $this->roles;
    // guarantee every user at least has ROLE_USER
    $roles[] = 'ROLE_USER';

    return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
    $this->roles = $roles;

    return $this;
    }

    /**
    * Returning a salt is only needed if you are not using a modern
    * hashing algorithm (e.g. bcrypt or sodium) in your security.yaml.
    *
    * @see UserInterface
    */
    public function getSalt(): ?string
    {
    return null;
    }

    /**
    * @see UserInterface
    */
    public function eraseCredentials()
    {
    // If you store any temporary, sensitive data on the user, clear it here
    // $this->plainPassword = null;
    }

    /**
     * @return mixed
     */
    public function getUid()
    {
        return $this->uid;
    }

    /**
     * @param mixed $uid
     */
    public function setUid($uid): void
    {
        $this->uid = $uid;
    }

    /**
     * @return mixed
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param mixed $username
     */
    public function setUsername($username): void
    {
        $this->username = $username;
    }
}