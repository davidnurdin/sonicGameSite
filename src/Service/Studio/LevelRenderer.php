<?php

namespace App\Service\Studio;

use App\Entity\Tileset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A level drawn on the server (no GD: Canvas), as the level viewer of the site shows it: its blocks, its objects
 * with their picture from the game, Sonic at his start. For the AI to see what it made (smaller than the game:
 * `px` pixels a block), with the collision tinted if asked, and the path of a test run (Simulator). Also the
 * thumbnail of the lists, around the start, like the editor makes it (level-view.js thumbnail()).
 */
final class LevelRenderer
{
    /** What the game draws behind the blocks (their transparent pixels). */
    private const BACKGROUND = "\x00\x00\x00\xff";
    private const SIZE = 32;

    /** @var array{0: Canvas, 1: array}|null the objects' pictures (public/img/objects.png, object-icons.json) */
    private ?array $icons = null;
    private ?Canvas $sonic = null;

    public function __construct(
        private Solidity $solidity,
        #[Autowire('%kernel.project_dir%/public')] private string $public,
    ) {
    }

    /**
     * @param array{width: int, height: int, blocks: string, start: array, objects: array} $level
     * @param array{image: string, solidity: list<int>, masks: array} $tiles
     * @param array{x?: int, y?: int, w?: int, h?: int} $region in blocks
     * @param list<array{0: float, 1: float}> $path points of a run (pixels of the level), drawn in magenta
     * @param list<array{0: float, 1: float, 2: string}> $marks [x, y, "rrggbb"]: crosses (deaths, where it got stuck)
     */
    public function render(array $level, array $tiles, int $px = 8, array $region = [], bool $collision = false, array $path = [], array $marks = [], bool $grid = false): Canvas
    {
        $x0 = max(0, min($level['width'] - 1, (int) ($region['x'] ?? 0)));
        $y0 = max(0, min($level['height'] - 1, (int) ($region['y'] ?? 0)));
        $w = max(1, min($level['width'] - $x0, (int) ($region['w'] ?? $level['width'])));
        $h = max(1, min($level['height'] - $y0, (int) ($region['h'] ?? $level['height'])));
        $factor = intdiv(self::SIZE, $px);
        $sheet = Canvas::fromPng($tiles['image']);
        $blocks = $collision ? $this->solidity->blocks($tiles['solidity'], $tiles['masks']) : [];
        // each block once, small and on the background, as rows of bytes; the picture is put together row by row
        $small = [];
        $lines = [];
        for ($y = 0; $y < $h; $y++) {
            $band = array_fill(0, $px, '');
            for ($x = 0; $x < $w; $x++) {
                $block = ord($level['blocks'][($y0 + $y) * $level['width'] + $x0 + $x]);
                if (!isset($small[$block])) {
                    $picture = $sheet?->crop(($block % Tileset::COLUMNS) * self::SIZE, intdiv($block, Tileset::COLUMNS) * self::SIZE, self::SIZE, self::SIZE)
                        ?? Canvas::blank(self::SIZE, self::SIZE);
                    if ($collision && isset($blocks[$block])) $picture = $this->tint($picture, $blocks[$block]);
                    $tile = Canvas::blank($px, $px);
                    $tile->fill(0, 0, $px, $px, self::BACKGROUND);
                    $tile->draw($picture->shrink($factor), 0, 0);
                    $small[$block] = str_split($tile->rgba(), $px * 4);
                }
                foreach ($small[$block] as $row => $bytes) $band[$row] .= $bytes;
            }
            array_push($lines, ...$band);
        }
        $canvas = Canvas::fromRgba($w * $px, $h * $px, implode('', $lines));
        if ($grid && $px >= 8) {
            for ($x = 0; $x < $w; $x++) $canvas->fill($x * $px, 0, 1, $canvas->height, "\x30\x40\x80\xff");
            for ($y = 0; $y < $h; $y++) $canvas->fill(0, $y * $px, $canvas->width, 1, "\x30\x40\x80\xff");
        }

        // the objects and Sonic: their pictures from the top left of their block, made smaller like the blocks
        [$sheetIcons, $index] = $this->icons();
        foreach ($level['objects'] as [$type, $ox, $oy]) {
            if ($ox < $x0 || $oy < $y0 || $ox >= $x0 + $w || $oy >= $y0 + $h) continue;
            $icon = $index[(string) $type] ?? null;
            if ($icon === null) {
                $canvas->fill(($ox - $x0) * $px + 1, ($oy - $y0) * $px + 1, max(1, $px - 2), max(1, $px - 2), "\xff\x80\x00\xff");
                continue;
            }
            [$sx, $sy, $iw, $ih, $dx, $dy] = $icon;
            $picture = $sheetIcons->crop($sx, $sy, $iw, $ih)->shrink($factor);
            $canvas->draw($picture, (int) floor((($ox - $x0) * self::SIZE + $dx) / $factor), (int) floor((($oy - $y0) * self::SIZE + $dy) / $factor));
        }
        [$sx, $sy] = $level['start'];
        if ($sx >= $x0 && $sy >= $y0 && $sx < $x0 + $w && $sy < $y0 + $h) {
            $canvas->draw($this->sonic()->shrink($factor), intdiv(($sx - $x0) * self::SIZE + 4, $factor), intdiv(($sy - $y0) * self::SIZE, $factor));
        }

        $dot = max(2, intdiv($px, 4));
        foreach ($path as [$x, $y]) {
            $canvas->fill((int) floor(($x - $x0 * self::SIZE) / $factor), (int) floor(($y - $y0 * self::SIZE) / $factor), $dot, $dot, "\xff\x00\xff\xff");
        }
        foreach ($marks as [$x, $y, $rgb]) {
            $cx = (int) floor(($x - $x0 * self::SIZE) / $factor);
            $cy = (int) floor(($y - $y0 * self::SIZE) / $factor);
            $colour = hex2bin($rgb) . "\xff";
            $size = max(3, intdiv($px, 2));
            for ($i = -$size; $i <= $size; $i++) {
                $canvas->fill($cx + $i, $cy + $i, $dot + 1, $dot + 1, $colour);
                $canvas->fill($cx + $i, $cy - $i, $dot + 1, $dot + 1, $colour);
            }
        }

        return $canvas;
    }

    /** The thumbnail of the lists: 40 x 20 blocks at most around the start, 480 x 240 pixels at most (12 px a block). */
    public function thumbnail(array $level, array $tiles): string
    {
        $w = min($level['width'], 40);
        $h = min($level['height'], 20);
        $x0 = max(0, min($level['width'] - $w, (int) round($level['start'][0] - $w / 3)));
        $y0 = max(0, min($level['height'] - $h, (int) round($level['start'][1] - $h * 0.6)));

        return $this->render($level, $tiles, 8, ['x' => $x0, 'y' => $y0, 'w' => $w, 'h' => $h])->png();
    }

    /** The solid pixels of a block tinted red (floor), orange (walls too), violet (only a ceiling). */
    private function tint(Canvas $picture, array $block): Canvas
    {
        if (!$block['solid']) return $picture;
        $rgba = $picture->rgba();
        $colour = $block['walls'] ? [255, 140, 0] : ($block['floor'] ? [255, 40, 40] : [180, 60, 255]);
        for ($y = 0; $y < self::SIZE; $y++) {
            $row = $block['rows'][$y];
            if ($row === 0) continue;
            for ($x = 0; $x < self::SIZE; $x++) {
                if ((($row >> (31 - $x)) & 1) === 0) continue;
                $at = ($y * self::SIZE + $x) * 4;
                $alpha = ord($rgba[$at + 3]) >= 128;
                for ($c = 0; $c < 3; $c++) {
                    $under = $alpha ? ord($rgba[$at + $c]) : 0x14;
                    $rgba[$at + $c] = chr(intdiv($under + $colour[$c], 2));
                }
                $rgba[$at + 3] = "\xff";
            }
        }

        return Canvas::fromRgba(self::SIZE, self::SIZE, $rgba);
    }

    private function icons(): array
    {
        return $this->icons ??= [
            Canvas::fromPng((string) file_get_contents($this->public . '/img/objects.png')) ?? Canvas::blank(1, 1),
            json_decode((string) file_get_contents($this->public . '/data/object-icons.json'), true),
        ];
    }

    /** The first picture of public/img/sonics.png: the blue Sonic, 23 x 32. */
    private function sonic(): Canvas
    {
        return $this->sonic ??= (Canvas::fromPng((string) file_get_contents($this->public . '/img/sonics.png')) ?? Canvas::blank(23, 32))->crop(0, 0, 23, 32);
    }
}
