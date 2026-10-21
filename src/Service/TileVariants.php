<?php

namespace App\Service;

use App\Entity\Level;
use App\Entity\Tileset;
use App\Service\Studio\Canvas;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The other pictures of a level's tiles that the game shows: the colour cycling of its zone (water, waterfalls,
 * lights: -cycleK), the lightning of Sky Base (-lightL-cycleK), the flash of the bonus stages (-flash), and the
 * underwater palette (-water) when the level has a water line. A zone of the game as it is: the game has them
 * (jeu.json "zone"). A changed copy of a zone: its picture recoloured as the game recolours the zone (the colour
 * maps of tools/game-data.py), so its untouched blocks keep moving and its new blocks too where they use those
 * colours. Under water without the map of a zone: a generic underwater tint.
 */
final class TileVariants
{
    /** The water line (the game's object $40). */
    public const WATER = 64;

    public function __construct(
        private EditorData $data,
        #[Autowire('%kernel.cache_dir%/tile-variants')] private string $dir,
    ) {
    }

    /** The zone of the game whose pictures the game takes as they are for $level, or null (TileVariants::of). */
    public function gameZone(Level $level): ?string
    {
        $zone = $level->getTileset()->getGameZone();
        // under water: only a zone the game has the underwater pictures of
        if ($zone !== null && $this->hasWater($level) && !isset($this->zoneData($zone)['variants']['-water'])) return null;

        return $zone;
    }

    /**
     * What jeu.json gives the game when it does not take a zone as it is: cycle {count, speed, lightning} and
     * variants {"-cycle0": PNG...}; empty when there is nothing to show.
     *
     * @return array{cycle?: array{count: int, speed: int, lightning: bool}, variants?: array<string, string>}
     */
    public function of(Level $level): array
    {
        if ($this->gameZone($level) !== null) return [];
        $tileset = $level->getTileset();
        $zone = $this->rootZone($tileset);
        $maps = $zone !== null ? ($this->zoneData($zone)['variants'] ?? []) : [];
        if (!$this->hasWater($level)) unset($maps['-water']);
        elseif (!isset($maps['-water'])) $maps['-water'] = null;   // the generic tint
        if ($maps === []) return [];
        $png = $tileset->getImage();
        $out = [];
        foreach ($maps as $variant => $map) {
            $out[$variant] = $this->variant($png, $variant, $map);
        }
        $cycle = $zone !== null ? ($this->zoneData($zone)['cycle'] ?? null) : null;

        return array_filter(['cycle' => is_array($cycle) && ($cycle['count'] ?? 0) > 1 ? $cycle : null, 'variants' => $out]);
    }

    public function hasWater(Level $level): bool
    {
        foreach ($level->getObjects() as $object) {
            if ((int) $object[0] === self::WATER) return true;
        }

        return false;
    }

    /** The zone of the game $tileset comes from (itself, or the first zone up its forks), or null. */
    private function rootZone(Tileset $tileset): ?string
    {
        for ($i = 0, $from = $tileset; $from !== null && $i < 20; $i++, $from = $from->getForkedFrom()) {
            if ($from->getZone() !== null) return $from->getZone();
        }

        return null;
    }

    private function zoneData(string $zone): array
    {
        return $this->data->zones()[$zone] ?? [];
    }

    /** $png recoloured with $map (null: the generic underwater tint), kept on disk by picture. */
    private function variant(string $png, string $variant, ?array $map): string
    {
        $file = $this->dir . '/' . hash('sha256', $png . '|' . json_encode($map)) . '.png';
        if (is_file($file)) return (string) file_get_contents($file);
        $canvas = Canvas::fromPng($png);
        if ($canvas === null) return $png;
        $canvas->recolour($map ?? array_combine($colours = array_keys($canvas->colours()), array_map(self::underwater(...), $colours)));
        $out = $canvas->png();
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        @file_put_contents($file, $out);

        return $out;
    }

    /**
     * A colour under water, near the Labyrinth's underwater palette: darker, bluer, in the Master System's colours
     * (0, 55, aa, ff a channel).
     */
    public static function underwater(string $rgb): string
    {
        [$r, $g, $b] = array_map('hexdec', str_split($rgb, 2));
        $light = 0.3 * $r + 0.59 * $g + 0.11 * $b;
        $level = fn(float $v) => sprintf('%02x', (int) round(max(0, min(255, $v)) / 85) * 85);

        return $level($r * 0.25) . $level($g * 0.6 + $light * 0.2) . $level($b * 0.5 + 85 + $light * 0.3);
    }
}
