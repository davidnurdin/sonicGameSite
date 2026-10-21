<?php

namespace App\Service\Studio;

/** What a tool gives back: data (JSON for the AI) and pictures (PNG). */
final class Result
{
    /** @param list<string> $images PNG files */
    public function __construct(public readonly array $data, public readonly array $images = [])
    {
    }
}
