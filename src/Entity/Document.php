<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToMany(mappedBy: 'document', targetEntity: PaperReferences::class)]
    private Collection $paperReferences;

    public function __construct()
    {
        $this->paperReferences = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return self
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return Collection<int, PaperReferences>
     */
    public function getPaperReferences(): Collection
    {
        return $this->paperReferences;
    }

    public function addPaperReference(PaperReferences $paperReference): self
    {
        if (!$this->paperReferences->contains($paperReference)) {
            $this->paperReferences->add($paperReference);
            $paperReference->setDocument($this);
        }

        return $this;
    }

    public function removePaperReference(PaperReferences $paperReference): self
    {
        if ($this->paperReferences->removeElement($paperReference)) {
            // set the owning side to null (unless already changed)
            if ($paperReference->getDocument() === $this) {
                $paperReference->setDocument(null);
            }
        }

        return $this;
    }
}
