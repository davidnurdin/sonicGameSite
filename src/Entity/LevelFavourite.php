<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A level a player put in his favourites: his list on the site and in the game's menu. */
#[ORM\Entity]
#[ORM\Table(name: 'level_favourite')]
#[ORM\UniqueConstraint(name: 'level_favourite_once', columns: ['level_id', 'user_id'])]
class LevelFavourite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Level::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Level $level;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Level $level, User $user)
    {
        $this->level = $level;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getLevel(): Level { return $this->level; }
    public function getUser(): User { return $this->user; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
