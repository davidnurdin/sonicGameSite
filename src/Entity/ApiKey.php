<?php

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A key a player gives an AI (or a script) to make levels, tiles, sprites and sounds on his account through the API
 * (/mcp, /api/v1: ApiKeyAuthenticator). Only its SHA-256 is kept: the key itself is shown once, when it is made.
 * Read only or read and write; it may expire; its owner revokes it whenever he wants.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
class ApiKey
{
    public const PREFIX = 'sk_sonic_';
    public const MAX_PER_USER = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $name;

    /** sha256 of the key, in hex */
    #[ORM\Column(length: 64, unique: true)]
    private string $hash;

    /** The start of the key, to tell keys apart on the page (sk_sonic_ + 4 characters). */
    #[ORM\Column(length: 16)]
    private string $hint;

    #[ORM\Column]
    private bool $canWrite;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private int $uses = 0;

    /** @param string $key the key made for it (ApiKey::generate()) */
    public function __construct(User $user, string $name, string $key, bool $canWrite, ?\DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->name = mb_substr($name, 0, 40);
        $this->hash = self::hash($key);
        $this->hint = substr($key, 0, strlen(self::PREFIX) + 4);
        $this->canWrite = $canWrite;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    /** A new key: the prefix and 40 random characters (238 bits). */
    public static function generate(): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $key = self::PREFIX;
        for ($i = 0; $i < 40; $i++) $key .= $chars[random_int(0, 61)];

        return $key;
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getName(): string { return $this->name; }
    public function getHint(): string { return $this->hint; }
    public function canWrite(): bool { return $this->canWrite; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function getUses(): int { return $this->uses; }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function used(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->uses++;
    }
}
