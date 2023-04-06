<?php

namespace App\Entity;

use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaperReferencesRepository::class)]
class PaperReferences
{
    public CONST SOURCE_METADATA_GROBID = 'GROBID';
    public CONST SOURCE_METADATA_EPI_USER = 'USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $docid = null;

    #[ORM\Column]
    private ?string $source = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updated_at = null;

    #[ORM\Column]
    private array $reference = [];

    #[ORM\Column]
    private ?int $uid = null;

    #[ORM\Column]
    private ?int $reference_order = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocid(): ?int
    {
        return $this->docid;
    }

    public function setDocid(int $docid): self
    {
        $this->docid = $docid;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        if (!in_array($source, array(self::SOURCE_METADATA_GROBID, self::SOURCE_METADATA_EPI_USER), true)) {
            throw new \InvalidArgumentException("Invalid status");
        }
        $this->source = $source;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(\DateTimeImmutable $updated_at): self
    {
        $this->updated_at = $updated_at;

        return $this;
    }

    public function getReference(): array
    {
        return $this->reference;
    }

    public function setReference(array $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getUid(): ?int
    {
        return $this->uid;
    }

    public function setUid(int $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getReferenceOrder(): ?int
    {
        return $this->reference_order;
    }

    public function setReferenceOrder(int $reference_order): self
    {
        $this->reference_order = $reference_order;

        return $this;
    }
}
