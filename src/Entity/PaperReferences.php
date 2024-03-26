<?php

namespace App\Entity;

use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaperReferencesRepository::class)]
class PaperReferences
{
    public const SOURCE_METADATA_GROBID = 'GROBID';
    public const SOURCE_METADATA_EPI_USER = 'USER';
    public const SOURCE_METADATA_BIBTEX_IMPORT = 'BIBTEX';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?string $source = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updated_at = null;

    #[ORM\Column]
    private array $reference = [];

    #[ORM\Column]
    private ?int $referenceOrder = null;

    #[ORM\Column(nullable: true)]
    private ?int $accepted = null;

    #[ORM\ManyToOne(cascade: ['persist'], inversedBy: 'paperReferences')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: UserInformations::class, cascade: ['persist'], inversedBy: 'paperReferences')]
    #[ORM\JoinColumn(name: 'uid', referencedColumnName: 'id',nullable: true)]
    private ?UserInformations $uid = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;
        return $this;
    }
    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        if (!in_array($source, array(self::SOURCE_METADATA_GROBID,
            self::SOURCE_METADATA_EPI_USER,
            self::SOURCE_METADATA_BIBTEX_IMPORT), true)) {
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

    public function getReferenceOrder(): ?int
    {
        return $this->referenceOrder;
    }

    public function setReferenceOrder(int $referenceOrder): self
    {
        $this->referenceOrder = $referenceOrder;

        return $this;
    }

    public function getAccepted(): ?int
    {
        return $this->accepted;
    }

    public function setAccepted(?int $accepted): self
    {
        $this->accepted = $accepted;

        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;

        return $this;
    }

    public function getUid(): ?UserInformations
    {
        return $this->uid;
    }

    public function setUid(?UserInformations $uid): self
    {
        $this->uid = $uid;

        return $this;
    }
}
