<?php

namespace App\Entity;

use App\Repository\RevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the history of a creation of the editors (a level, tiles, sprites, a sound): who did what, when.
 * The actions that change it keep what it was then (data: its content, JSON, compressed), to look at it again or
 * to open it in the editor. Kept when the creation is deleted (its id stays): the history is complete.
 */
#[ORM\Entity(repositoryClass: RevisionRepository::class)]
#[ORM\Table(name: 'revision')]
#[ORM\Index(name: 'revision_creation', columns: ['kind', 'creation_id'])]
#[ORM\Index(name: 'revision_created', columns: ['created_at'])]
class Revision
{
    /** Its content changed: create (new, or forked from another), save, restore (an old version saved again). */
    public const CONTENT = ['create', 'fork', 'save', 'restore'];
    public const ACTIONS = ['create', 'fork', 'save', 'restore', 'forked', 'member', 'unmember', 'trash', 'untrash', 'delete'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** level, tileset, sprites, sound (Creations::KINDS) */
    #[ORM\Column(length: 8)]
    private string $kind;

    #[ORM\Column]
    private int $creationId;

    /** Who did it (null: the account is gone). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 12)]
    private string $action;

    /** Its name then (the history of a deleted creation still says what it was). */
    #[ORM\Column(length: 40)]
    private string $name;

    /** @var list<array{0: string, 1?: array<string, scalar>}> what changed: [message, its values], translated when shown */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    /** Another creation of the same kind it is about: the original of a fork, the fork made from it. */
    #[ORM\Column(nullable: true)]
    private ?int $otherId;

    /** The content then (Creations::snapshot), JSON compressed; null for the actions that don't change it. */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private $data;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $hash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $kind, int $creationId, ?User $user, string $action, string $name, array $changes,
        ?int $otherId = null, ?string $data = null, ?string $hash = null)
    {
        $this->kind = $kind;
        $this->creationId = $creationId;
        $this->user = $user;
        $this->action = $action;
        $this->name = mb_substr($name, 0, 40);
        $this->changes = $changes;
        $this->otherId = $otherId;
        $this->data = $data;
        $this->hash = $hash;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** For the creations made before there was a history (app:history): when their content was saved. */
    public function setCreatedAt(\DateTimeImmutable $at): void { $this->createdAt = $at; }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getCreationId(): int { return $this->creationId; }
    public function getUser(): ?User { return $this->user; }
    public function getAction(): string { return $this->action; }
    public function getName(): string { return $this->name; }
    public function getChanges(): array { return $this->changes; }
    public function getOtherId(): ?int { return $this->otherId; }
    public function getHash(): ?string { return $this->hash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function hasData(): bool { return $this->data !== null; }

    /** The content then, or null. */
    public function getSnapshot(): ?array
    {
        $data = is_resource($this->data) ? stream_get_contents($this->data) : $this->data;
        if (!is_string($data)) return null;
        $json = @gzuncompress($data);
        $snapshot = is_string($json) ? json_decode($json, true) : null;

        return is_array($snapshot) ? $snapshot : null;
    }
}
