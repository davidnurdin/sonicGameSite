<?php

namespace App\Entity;

use App\Repository\TilesetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A set of blocks (the game's "tiles"): up to 256 blocks of 32x32 pixels, 16 a row in one PNG, and for each its
 * collision byte, as in the game (level<N>.solidity: the low 6 bits are one of the 47 shapes of
 * public/data/solidity-shapes.json, bit 7 draws the block in front of Sonic). A level uses one set. The zones
 * of the game are sets without owner (app:zones, data/game/).
 */
#[ORM\Entity(repositoryClass: TilesetRepository::class)]
#[ORM\Table(name: 'tileset')]
class Tileset
{
    use Trashable;

    public const MAX = 256;
    public const COLUMNS = 16;
    public const SIZE = 32;
    public const MAX_PNG = 1024 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The one it was forked from (copied to be changed): null for an original, or once that one is deleted. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'forked_from_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $forkedFrom = null;

    /** Null: a zone of the game. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    /** A zone of the game: its key (green-hill...). */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $zone = null;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column]
    private bool $public = false;

    /** @var list<int> one byte a block */
    #[ORM\Column(type: Types::JSON)]
    private array $solidity = [];

    /** @var list<int> one byte a block: springs, spikes... (the game's CollisionSystem::B_*), 0 = nothing */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $behaviour = [];

    /**
     * Collisions drawn in the editor instead of a shape of the game, by block: {r: 32 rows of 8 hex digits (bit 31 = the
     * left pixel), w: 1 floor, 2 walls, 4 ceiling} (see the game's Level::applyMask).
     * @var array<int, array{r: list<string>, w: int}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $masks = [];

    /**
     * Animated blocks: block => {f: the blocks shown in turn (2-16, pictures of this set), t: how long each is shown, in
     * frames of the game (60 a second)}. The block keeps its own collision and behaviour (see EditorData::animations).
     * @var array<int, array{f: list<int>, t: int}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $animations = [];

    /** The zone whose look its moving objects take (platforms...): the game's SP, 0 = Green Hill. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $style = 0;

    #[ORM\Column(type: Types::BLOB)]
    private $image;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?User $user, string $name, string $png, array $solidity, array $behaviour = [], int $style = 0, array $masks = [], array $animations = [])
    {
        $this->user = $user;
        $this->name = $name;
        $this->image = $png;
        $this->solidity = $solidity;
        $this->behaviour = $behaviour;
        $this->style = $style;
        $this->masks = $masks;
        $this->animations = $animations;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getForkedFrom(): ?self { return $this->forkedFrom; }
    public function setForkedFrom(?self $from): void { $this->forkedFrom = $from; }
    public function getUser(): ?User { return $this->user; }
    public function getZone(): ?string { return $this->zone; }
    public function setZone(?string $zone): void { $this->zone = $zone; }
    /** The zone of the game whose pictures these are: a zone, or a copy of one with its pictures untouched (the game keeps its colour cycles). */
    public function getGameZone(): ?string
    {
        $from = $this;
        for ($i = 0; $from !== null && $from->zone === null && $i < 20; $i++) $from = $from->forkedFrom;
        if ($from === null || $from->zone === null) return null;

        return $from === $this || $from->getImage() === $this->getImage() ? $from->zone : null;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): void { $this->public = $public; }
    /** @return list<int> */
    public function getSolidity(): array { return $this->solidity; }
    public function getCount(): int { return count($this->solidity); }
    /** @return list<int> as many as the blocks */
    public function getBehaviour(): array { return array_pad(array_slice($this->behaviour, 0, $this->getCount()), $this->getCount(), 0); }
    /** @return array<int, array{r: list<string>, w: int}> only the blocks there are */
    public function getMasks(): array { return array_filter($this->masks, fn($k) => $k < $this->getCount(), ARRAY_FILTER_USE_KEY); }
    /** @return array<int, array{f: list<int>, t: int}> the animated blocks (none beyond the blocks of the set) */
    public function getAnimations(): array
    {
        $count = $this->getCount();

        return array_filter($this->animations, fn(array $a, $k) => $k < $count && max($a['f']) < $count, ARRAY_FILTER_USE_BOTH);
    }

    public function getStyle(): int { return $this->style; }
    public function setStyle(int $style): void { $this->style = $style; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getImage(): string
    {
        return is_resource($this->image) ? (string) stream_get_contents($this->image, -1, 0) : (string) $this->image;
    }

    public function setBlocks(string $png, array $solidity, ?array $behaviour = null, ?array $masks = null, ?array $animations = null): void
    {
        $this->image = $png;
        $this->solidity = $solidity;
        if ($behaviour !== null) $this->behaviour = $behaviour;
        if ($masks !== null) $this->masks = $masks;
        if ($animations !== null) $this->animations = $animations;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user !== null && $this->user->getId() === $user->getId();
    }

    /** Anyone may see it and build a level with it. */
    public function canUse(?User $user): bool
    {
        return !$this->isTrashed() && ($this->public || $this->isOwnedBy($user));
    }
}
