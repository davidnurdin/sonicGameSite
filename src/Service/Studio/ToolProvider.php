<?php

namespace App\Service\Studio;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A group of tools of the studio (levels, tiles, sprites, sounds, the account). */
#[AutoconfigureTag('app.studio_tools')]
interface ToolProvider
{
    /** @return list<Tool> */
    public function tools(): array;
}
