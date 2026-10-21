<?php

namespace App\Entity;

use App\Repository\LevelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A level made in the editor, laid out like the game's (level<N>.bin): width x height blocks of 32 pixels, one
 * byte each (a block of its Tileset), Sonic's start and the objects (type of the game's objects, x, y in
 * blocks). Public: everybody sees it, signed in or not. Played in the game (EditorController::game).
 */
#[ORM\Entity(repositoryClass: LevelRepository::class)]
#[ORM\Table(name: 'level')]
#[ORM\Index(name: 'level_public', columns: ['public', 'updated_at'])]
class Level
{
    public const MIN_WIDTH = 8;
    public const MIN_HEIGHT = 6;
    public const MAX_SIDE = 256;
    /** Blocks at most (the game's acts have 4096). */
    public const MAX_BLOCKS = 16384;
    public const MAX_OBJECTS = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tileset::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tileset $tileset;

    /** Its own sprites (null: the game's). */
    #[ORM\ManyToOne(targetEntity: SpriteSet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpriteSet $spriteSet = null;

    /** Its music, made in the sound editor, instead of a song of the game ($music). */
    #[ORM\ManyToOne(targetEntity: Sound::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sound $musicSound = null;

    /** @var array<int, int> sounds of the game (SoundData::GAME_SFX) => the sound effect (Sound id) played instead */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $sfx = [];

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column]
    private bool $public = false;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $width;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $height;

    #[ORM\Column(type: Types::BLOB)]
    private $blocks;

    /** @var array{0: int, 1: int} */
    #[ORM\Column(type: Types::JSON)]
    private array $start;

    /** @var list<array{0: int, 1: int, 2: int}> type, x, y */
    #[ORM\Column(type: Types::JSON)]
    private array $objects = [];

    /** Its music: a song of the game (SoundManager::MUSIC_BY_INDEX, see EditorData::MUSICS). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $music = 0;

    /** A small picture of the whole level (PNG, made by the editor when it saves): lists, links. */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private $thumb = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Tileset $tileset, string $name, int $width, int $height, string $blocks, array $start, array $objects)
    {
        $this->user = $user;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($tileset, $width, $height, $blocks, $start, $objects);
    }

    public function update(Tileset $tileset, int $width, int $height, string $blocks, array $start, array $objects): void
    {
        $this->tileset = $tileset;
        $this->width = $width;
        $this->height = $height;
        $this->blocks = $blocks;
        $this->start = $start;
        $this->objects = $objects;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTileset(): Tileset { return $this->tileset; }
    public function getSpriteSet(): ?SpriteSet { return $this->spriteSet; }
    public function getMusicSound(): ?Sound { return $this->musicSound; }
    public function setMusicSound(?Sound $sound): void { $this->musicSound = $sound; }
    /** @return array<int, int> */
    public function getSfx(): array { return $this->sfx; }
    /** @param array<int, int> $sfx */
    public function setSfx(array $sfx): void { $this->sfx = $sfx; }
    public function setSpriteSet(?SpriteSet $spriteSet): void { $this->spriteSet = $spriteSet; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): void { $this->public = $public; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getStart(): array { return $this->start; }
    public function getObjects(): array { return $this->objects; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getBlocks(): string
    {
        return is_resource($this->blocks) ? (string) stream_get_contents($this->blocks, -1, 0) : (string) $this->blocks;
    }

    public function getMusic(): int { return $this->music; }
    public function setMusic(int $music): void { $this->music = $music; }

    public function getThumb(): ?string
    {
        if ($this->thumb === null) return null;

        return is_resource($this->thumb) ? (string) stream_get_contents($this->thumb, -1, 0) : (string) $this->thumb;
    }

    public function setThumb(?string $png): void { $this->thumb = $png; }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user->getId() === $user->getId();
    }

    public function canSee(?User $user): bool
    {
        return $this->public || $this->isOwnedBy($user);
    }
}
