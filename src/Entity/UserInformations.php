<?php

namespace App\Entity;

use App\Repository\UserInformationsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserInformationsRepository::class)]
class UserInformations
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $surname = null;

    #[ORM\OneToMany(mappedBy: 'uid', targetEntity: PaperReferences::class,cascade: ['persist'])]
    private Collection $paperReferences;

    public function __construct()
    {
        $this->paperReferences = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }
    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    /**
     * @return Collection<int, PaperReferences>
     */
    /**
     * @return Collection
     */
    public function getPaperReferences(): Collection
    {
        return $this->paperReferences;
    }

    /**
     * @param PaperReferences $paperReference
     * @return UserInformations
     */
    public function addPaperReferences(PaperReferences $paperReference): self
    {
        if (!$this->paperReferences->contains($paperReference)){
            $this->paperReferences[] = $paperReference;
        }
        return $this;
    }

//    public function removeUid(PaperReferences $uid): self
//    {
//        if ($this->uid->removeElement($uid)) {
//            // set the owning side to null (unless already changed)
//            if ($uid->getUid() === $this) {
//                $uid->setUid(null);
//            }
//        }
//
//        return $this;
//    }
}
