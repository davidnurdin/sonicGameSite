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
    public const MAX = 256;
    public const COLUMNS = 16;
    public const SIZE = 32;
    public const MAX_PNG = 1024 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    /** The zone whose look its moving objects take (platforms...): the game's SP, 0 = Green Hill. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $style = 0;

    #[ORM\Column(type: Types::BLOB)]
    private $image;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?User $user, string $name, string $png, array $solidity, array $behaviour = [], int $style = 0)
    {
        $this->user = $user;
        $this->name = $name;
        $this->image = $png;
        $this->solidity = $solidity;
        $this->behaviour = $behaviour;
        $this->style = $style;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getZone(): ?string { return $this->zone; }
    public function setZone(?string $zone): void { $this->zone = $zone; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): void { $this->public = $public; }
    /** @return list<int> */
    public function getSolidity(): array { return $this->solidity; }
    public function getCount(): int { return count($this->solidity); }
    /** @return list<int> as many as the blocks */
    public function getBehaviour(): array { return array_pad(array_slice($this->behaviour, 0, $this->getCount()), $this->getCount(), 0); }
    public function getStyle(): int { return $this->style; }
    public function setStyle(int $style): void { $this->style = $style; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getImage(): string
    {
        return is_resource($this->image) ? (string) stream_get_contents($this->image, -1, 0) : (string) $this->image;
    }

    public function setBlocks(string $png, array $solidity, ?array $behaviour = null): void
    {
        $this->image = $png;
        $this->solidity = $solidity;
        if ($behaviour !== null) $this->behaviour = $behaviour;
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
        return $this->public || $this->isOwnedBy($user);
    }
}
