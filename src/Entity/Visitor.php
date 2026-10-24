<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A visitor of the site (VisitorCounter): counted once per address, and only from the JavaScript of the pages (the
 * robots that don't run it are not). The address itself is not kept: a keyed hash of it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'visitor')]
#[ORM\UniqueConstraint(name: 'visitor_address', columns: ['address_hash'])]
class Visitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** hash_hmac sha256 of the address, keyed with the secret of the site. */
    #[ORM\Column(length: 64)]
    private string $addressHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $addressHash)
    {
        $this->addressHash = $addressHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAddressHash(): string { return $this->addressHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
