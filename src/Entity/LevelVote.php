<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The stars (1 to 5) a player gave a level of another player: one vote each, changed when he votes again. */
#[ORM\Entity]
#[ORM\Table(name: 'level_vote')]
#[ORM\UniqueConstraint(name: 'level_vote_once', columns: ['level_id', 'user_id'])]
class LevelVote
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

    #[ORM\Column(type: Types::SMALLINT)]
    private int $stars;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Level $level, User $user, int $stars)
    {
        $this->level = $level;
        $this->user = $user;
        $this->setStars($stars);
    }

    public function getLevel(): Level { return $this->level; }
    public function getUser(): User { return $this->user; }
    public function getStars(): int { return $this->stars; }

    public function setStars(int $stars): void
    {
        $this->stars = max(1, min(5, $stars));
        $this->updatedAt = new \DateTimeImmutable();
    }
}
