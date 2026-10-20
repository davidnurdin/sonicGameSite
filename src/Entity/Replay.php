<?php

namespace App\Entity;

use App\Repository\ReplayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The replay of a game, sent by the game's page at its end (ReplayRecorder.php of the game): an act played
 * alone, a match against the computer or online. Online, every player sends his own (the same match id).
 * "Revoir" opens the game with ?replay=<id>: it shows it again.
 */
#[ORM\Entity(repositoryClass: ReplayRepository::class)]
#[ORM\Table(name: 'replay')]
#[ORM\UniqueConstraint(name: 'replay_match_user', columns: ['match_id', 'user_id'])]
#[ORM\Index(name: 'replay_created', columns: ['created_at'])]
class Replay
{
    public const KINDS = ['solo', 'versus', 'online'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 8)]
    private string $kind;

    #[ORM\Column(length: 12)]
    private string $mode;

    #[ORM\Column]
    private int $level;

    /** Online: the id of the match (Versus::matchId), the same for every player. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $matchId;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $players;

    /** @var list<array{0: string, 1: string}> name, score, by rank */
    #[ORM\Column(type: Types::JSON)]
    private array $result;

    #[ORM\Column]
    private float $duration;

    /** The replay itself: JSON, gzip (sent back as it is). */
    #[ORM\Column(type: Types::BLOB)]
    private $data;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $kind, string $mode, int $level, ?string $matchId, array $players, array $result, float $duration, string $data)
    {
        $this->user = $user;
        $this->kind = $kind;
        $this->mode = $mode;
        $this->level = $level;
        $this->matchId = $matchId;
        $this->players = $players;
        $this->result = $result;
        $this->duration = $duration;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getKind(): string { return $this->kind; }
    public function getMode(): string { return $this->mode; }
    public function getLevel(): int { return $this->level; }
    public function getMatchId(): ?string { return $this->matchId; }
    /** @return list<string> */
    public function getPlayers(): array { return $this->players; }
    public function getResult(): array { return $this->result; }
    public function getDuration(): float { return $this->duration; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getData(): string
    {
        return is_resource($this->data) ? (string) stream_get_contents($this->data, -1, 0) : (string) $this->data;
    }
}
