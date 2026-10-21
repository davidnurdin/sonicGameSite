<?php

namespace App\Entity;

use App\Repository\SpriteSetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sprites drawn again in the sprite editor: some sheets of the game (Sonic, the badniks...), each a PNG the size of
 * the game's one (its transparent pixels are the game's colour key). A level may use a set: the game draws its
 * sheets instead of its own while the level is played (LevelManager::loadCustom). The sheets not drawn again are
 * the game's.
 */
#[ORM\Entity(repositoryClass: SpriteSetRepository::class)]
#[ORM\Table(name: 'sprite_set')]
class SpriteSet
{
    /** The sheets of the game a set may draw again: name => [width, height, label] (the game's LevelManager::SPRITE_SHEETS). */
    public const SHEETS = [
        'sonic' => [435, 128, 'Sonic'],
        'enemies' => [648, 176, 'Badniks'],
        'objects' => [640, 216, 'Objets'],
        'rom' => [1242, 82, 'Divers (poses de Sonic, plateformes…)'],
        'eggman' => [508, 231, 'Dr. Robotnik'],
        'boss' => [2048, 98, 'Boss'],
    ];
    public const MAX_PNG = 512 * 1024;

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

    #[ORM\Column]
    private bool $public = false;

    /** @var array<string, string> sheet => PNG in base64 (only the sheets drawn again) */
    #[ORM\Column(type: Types::JSON)]
    private array $sheets = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $name, array $sheets = [])
    {
        $this->user = $user;
        $this->name = $name;
        $this->sheets = $sheets;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getForkedFrom(): ?self { return $this->forkedFrom; }
    public function setForkedFrom(?self $from): void { $this->forkedFrom = $from; }
    public function getUser(): User { return $this->user; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): void { $this->public = $public; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return array<string, string> sheet => PNG in base64 */
    public function getSheets(): array { return array_intersect_key($this->sheets, self::SHEETS); }

    /** The PNG of a sheet drawn again, or null (the game's). */
    public function getSheet(string $sheet): ?string
    {
        $png = $this->getSheets()[$sheet] ?? null;

        return $png === null ? null : (base64_decode($png, true) ?: null);
    }

    /** @param array<string, string> $sheets sheet => PNG in base64 */
    public function setSheets(array $sheets): void
    {
        $this->sheets = $sheets;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user->getId() === $user->getId();
    }

    /** Anyone may see it and use it in a level. */
    public function canUse(?User $user): bool
    {
        return $this->public || $this->isOwnedBy($user);
    }

    /** A PNG of exactly $width x $height pixels (its header: there is no GD in the image of the site). */
    public static function isPng(string $png, int $width, int $height): bool
    {
        if (strlen($png) < 33 || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || substr($png, 12, 4) !== 'IHDR') return false;
        $size = unpack('Nwidth/Nheight', substr($png, 16, 8));

        return $size['width'] === $width && $size['height'] === $height;
    }
}
