<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A player's account. Its username is also his name in the game's online rooms, so it follows the game's
 * rule (Online::cleanName): 3 to 8 letters or digits, kept in capitals (the game's font has no lower case).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['username'], message: 'Ce nom est déjà pris.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, unique: true)]
    #[Assert\NotBlank(message: 'Choisis un nom.')]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{3,8}$/', message: 'De 3 à 8 lettres ou chiffres, sans espace ni accent.')]
    private string $username = '';

    /** Optional, never shown: to get in touch with the player (not checked by an email for now). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Cette adresse email ne semble pas valide.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** The hashed password. */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = strtoupper(trim($username));

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = trim((string) $email);
        $this->email = $email === '' ? null : mb_strtolower($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Only the hash is kept in the session (a CRC of it, Symfony >= 7.3). */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
