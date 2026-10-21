<?php

namespace App\Service\Studio;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The collision of the blocks as the game reads it (sonicGamePhp Level::applySolidity / applyMask): the low 6 bits
 * of a block's solidity byte are one of 47 shapes (public/data/solidity-shapes.json: 32 rows of 32 bits, bit 31 =
 * the left pixel), bit 7 draws the block in front of Sonic. The sides that stop him come from the ROM: only the
 * full shapes 22 ($16) and 30 ($1E) are walls; 22 and 24-40 stop him from below; 24-38 are never stood on. A
 * collision drawn in the tile editor (masks) has its own sides: w bit 0 floor, 1 walls, 2 ceiling.
 */
final class Solidity
{
    public const FULL = 22;
    public const HALF = 16;

    /** @var list<list<int>>|null */
    private ?array $shapes = null;

    public function __construct(#[Autowire('%kernel.project_dir%/public/data/solidity-shapes.json')] private string $file)
    {
    }

    /** @return list<list<int>> each shape: 32 rows, a 32-bit int each (bit 31 = x 0) */
    public function shapes(): array
    {
        return $this->shapes ??= array_map(fn(array $rows) => array_map('hexdec', $rows), json_decode((string) file_get_contents($this->file), true));
    }

    /** @return array{floor: bool, walls: bool, ceiling: bool} the sides of a shape that stop Sonic */
    public static function ways(int $shape): array
    {
        return [
            'floor' => !($shape >= 0x18 && $shape <= 0x26),
            'walls' => $shape === 0x16 || $shape === 0x1E,
            'ceiling' => $shape === 0x16 || ($shape >= 0x18 && $shape <= 0x28),
        ];
    }

    /**
     * The collision of each block of a set: rows (32 ints) and its sides.
     *
     * @param list<int> $solidity
     * @param array<int, array{r: list<string>, w: int}> $masks
     * @return list<array{rows: list<int>, floor: bool, walls: bool, ceiling: bool, solid: bool}>
     */
    public function blocks(array $solidity, array $masks = []): array
    {
        $shapes = $this->shapes();
        $out = [];
        foreach ($solidity as $i => $value) {
            if (isset($masks[$i])) {
                $rows = array_map('hexdec', $masks[$i]['r']);
                $w = $masks[$i]['w'];
                $ways = ['floor' => ($w & 1) !== 0, 'walls' => ($w & 2) !== 0, 'ceiling' => ($w & 4) !== 0];
            } else {
                $shape = $value & 0x3F;
                $rows = $shapes[$shape] ?? array_fill(0, 32, 0);
                $ways = self::ways($shape);
            }
            $out[] = ['rows' => $rows, 'solid' => array_sum($rows) > 0] + $ways;
        }

        return $out;
    }

    /** A shape in 8 x 8 characters ('#' solid, '.' empty), for the AI to see it. */
    public function ascii(int $shape): array
    {
        $rows = $this->shapes()[$shape] ?? [];
        $out = [];
        for ($y = 2; $y < 32; $y += 4) {
            $line = '';
            for ($x = 2; $x < 32; $x += 4) $line .= (($rows[$y] ?? 0) >> (31 - $x)) & 1 ? '#' : '.';
            $out[] = $line;
        }

        return $out;
    }

    /** @return list<?int> the first solid row of each column of a shape (null: none), its surface */
    public function surface(int $shape): array
    {
        $rows = $this->shapes()[$shape] ?? [];
        $out = [];
        for ($x = 0; $x < 32; $x++) {
            $top = null;
            for ($y = 0; $y < 32; $y++) {
                if ((($rows[$y] ?? 0) >> (31 - $x)) & 1) {
                    $top = $y;
                    break;
                }
            }
            $out[] = $top;
        }

        return $out;
    }
}
