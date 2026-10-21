<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A creation its owner put in his trash (Creations::trash): hidden from everybody else and from the lists, kept
 * for Creations::TRASH_DAYS days (the levels already made with it still have it), then deleted for good.
 */
trait Trashable
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    public function getTrashedAt(): ?\DateTimeImmutable { return $this->trashedAt; }
    public function isTrashed(): bool { return $this->trashedAt !== null; }
    public function setTrashedAt(?\DateTimeImmutable $at): void { $this->trashedAt = $at; }
}
