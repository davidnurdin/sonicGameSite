<?php

namespace App\Service\Studio;

use App\Entity\ApiKey;
use App\Entity\User;

/** Who calls a tool: the player, through one of his keys. */
final class Context
{
    public function __construct(public readonly User $user, public readonly ApiKey $key)
    {
    }
}
