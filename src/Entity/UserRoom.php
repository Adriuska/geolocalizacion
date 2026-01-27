<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_room')]
class UserRoom
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Usuarios::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Usuarios $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PrivateRoom::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrivateRoom $room = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $joinedAt = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTime();
    }

    public function getUser(): ?Usuarios
    {
        return $this->user;
    }

    public function setUser(?Usuarios $user): static
    {
        $this->user = $user;
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

    public function getJoinedAt(): ?\DateTimeInterface
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(\DateTimeInterface $joinedAt): static
    {
        $this->joinedAt = $joinedAt;
        return $this;
    }
}
