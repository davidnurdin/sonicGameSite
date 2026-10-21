<?php

namespace App\Entity;

use App\Repository\MemberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player its owner lets change a creation of the editors with him (a level, tiles, sprites, a sound): he sees it
 * even private, opens it in the editor and saves it. Only the owner deletes it and chooses who is in.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'creation_member')]
#[ORM\UniqueConstraint(name: 'member_creation_user', columns: ['kind', 'creation_id', 'user_id'])]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $kind;

    #[ORM\Column]
    private int $creationId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $kind, int $creationId, User $user)
    {
        $this->kind = $kind;
        $this->creationId = $creationId;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getCreationId(): int { return $this->creationId; }
    public function getUser(): User { return $this->user; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
