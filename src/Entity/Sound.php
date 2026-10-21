<?php

namespace App\Entity;

use App\Repository\SoundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A music or a sound effect made in the sound editor (public/js/sound-engine.js plays them): a music is notes on the
 * 4 voices of the Master System's sound chip (2 squares, a bass, the noise), a sound effect the settings of a
 * generator (wave, pitch slide, envelope...). See SoundData for both. The editor also sends it rendered (SoundAudio):
 * what the game plays, for the levels that take it as their music or instead of a sound of the game.
 */
#[ORM\Entity(repositoryClass: SoundRepository::class)]
#[ORM\Table(name: 'sound')]
class Sound
{
    public const MUSIC = 'music';
    public const SFX = 'sfx';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The one it was forked from (copied to be changed): null for an original, or once that one is deleted. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'forked_from_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $forkedFrom = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $name;

    /** self::MUSIC or self::SFX */
    #[ORM\Column(length: 5)]
    private string $kind;

    #[ORM\Column]
    private bool $public = false;

    #[ORM\Column(type: Types::JSON)]
    private array $data;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $name, string $kind, array $data)
    {
        $this->user = $user;
        $this->name = $name;
        $this->kind = $kind === self::MUSIC ? self::MUSIC : self::SFX;
        $this->data = $data;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getForkedFrom(): ?self { return $this->forkedFrom; }
    public function setForkedFrom(?self $from): void { $this->forkedFrom = $from; }
    public function getUser(): User { return $this->user; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getKind(): string { return $this->kind; }
    public function isMusic(): bool { return $this->kind === self::MUSIC; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): void { $this->public = $public; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setData(array $data): void
    {
        $this->data = $data;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user->getId() === $user->getId();
    }

    public function canSee(?User $user): bool
    {
        return $this->public || $this->isOwnedBy($user);
    }
}
