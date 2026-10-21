<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The best time of a player on a level of the site, as it is now ($version: Level::getContentVersion; once the
 * level changes, its times start again). Its replay is kept (ReplayRepository::keepLatest leaves it): the
 * ghost of the record in the game, "Revoir" on the site.
 */
#[ORM\Entity]
#[ORM\Table(name: 'level_record')]
#[ORM\UniqueConstraint(name: 'level_record_once', columns: ['level_id', 'user_id', 'version'])]
#[ORM\Index(name: 'level_record_best', columns: ['level_id', 'version', 'time'])]
class LevelRecord
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

    #[ORM\Column(length: 16)]
    private string $version;

    /** Seconds, as the game's timer shows it when the level is completed. */
    #[ORM\Column]
    private float $time;

    #[ORM\ManyToOne(targetEntity: Replay::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Replay $replay = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Level $level, User $user, string $version, float $time, ?Replay $replay)
    {
        $this->level = $level;
        $this->user = $user;
        $this->version = $version;
        $this->improve($time, $replay);
    }

    public function getId(): ?int { return $this->id; }
    public function getLevel(): Level { return $this->level; }
    public function getUser(): User { return $this->user; }
    public function getTime(): float { return $this->time; }
    public function getReplay(): ?Replay { return $this->replay; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function improve(float $time, ?Replay $replay): void
    {
        $this->time = $time;
        $this->replay = $replay;
        $this->createdAt = new \DateTimeImmutable();
    }
}
