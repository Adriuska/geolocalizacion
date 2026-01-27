<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $content = null;

    #[ORM\ManyToOne(targetEntity: Usuarios::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Usuarios $sender = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isGlobal = true;

    #[ORM\ManyToOne(targetEntity: PrivateRoom::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?PrivateRoom $room = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $distanceWhenSent = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isGlobal = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getSender(): ?Usuarios
    {
        return $this->sender;
    }

    public function setSender(?Usuarios $sender): static
    {
        $this->sender = $sender;
        return $this;
    }

    public function getIsGlobal(): bool
    {
        return $this->isGlobal;
    }

    public function setIsGlobal(bool $isGlobal): static
    {
        $this->isGlobal = $isGlobal;
        return $this;
    }

    public function getRoom(): ?PrivateRoom
    {
        return $this->room;
    }

    public function setRoom(?PrivateRoom $room): static
    {
        $this->room = $room;
        $this->isGlobal = $room === null;
        return $this;
    }

    public function getDistanceWhenSent(): ?string
    {
        return $this->distanceWhenSent;
    }

    public function setDistanceWhenSent(?string $distanceWhenSent): static
    {
        $this->distanceWhenSent = $distanceWhenSent;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
