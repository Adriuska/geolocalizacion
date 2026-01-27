<?php

namespace App\Entity;

use App\Repository\PrivateRoomRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrivateRoomRepository::class)]
#[ORM\Table(name: 'private_room')]
class PrivateRoom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: Usuarios::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Usuarios $createdBy = null;

    #[ORM\Column(type: 'integer')]
    private int $participantsCount = 0;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
        $this->createdAt = new \DateTime();
        $this->participantsCount = 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getCreatedBy(): ?Usuarios
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Usuarios $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getParticipantsCount(): int
    {
        return $this->participantsCount;
    }

    public function setParticipantsCount(int $participantsCount): static
    {
        $this->participantsCount = $participantsCount;
        return $this;
    }

    public function incrementParticipants(): static
    {
        $this->participantsCount++;
        return $this;
    }

    public function decrementParticipants(): static
    {
        $this->participantsCount = max(0, $this->participantsCount - 1);
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
