<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invitation')]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Usuarios::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Usuarios $sender = null;

    #[ORM\ManyToOne(targetEntity: Usuarios::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Usuarios $receiver = null;

    #[ORM\ManyToOne(targetEntity: PrivateRoom::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrivateRoom $room = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; // pending, accepted, rejected

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = 'pending';
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getReceiver(): ?Usuarios
    {
        return $this->receiver;
    }

    public function setReceiver(?Usuarios $receiver): static
    {
        $this->receiver = $receiver;
        return $this;
    }

    public function getRoom(): ?PrivateRoom
    {
        return $this->room;
    }

    public function setRoom(?PrivateRoom $room): static
    {
        $this->room = $room;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function accept(): static
    {
        $this->status = 'accepted';
        return $this;
    }

    public function reject(): static
    {
        $this->status = 'rejected';
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
