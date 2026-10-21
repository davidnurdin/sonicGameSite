<?php

namespace App\Service\Studio;

use App\Service\Png;

/**
 * A picture in memory (RGBA, 4 bytes a pixel, rows from the top) for the studio of the API: the sets of tiles and
 * the sheets of sprites an AI draws, and the previews of the levels. The image of the site has no GD.
 */
final class Canvas
{
    /** @param list<string> $rows each row: $width x 4 bytes (rows apart: drawing changes a row, not the whole picture) */
    private function __construct(public readonly int $width, public readonly int $height, private array $rows)
    {
    }

    public static function blank(int $width, int $height): self
    {
        return new self($width, $height, array_fill(0, $height, str_repeat("\0\0\0\0", $width)));
    }

    /** A PNG as the editors save them (8 bits RGBA or RGB); null if it is not one. */
    public static function fromPng(string $png): ?self
    {
        $image = Png::rgba($png);

        return $image === null ? null : self::fromRgba($image[0], $image[1], $image[2]);
    }

    public static function fromRgba(int $width, int $height, string $rgba): self
    {
        if (strlen($rgba) !== $width * $height * 4) throw new \InvalidArgumentException('size');

        return new self($width, $height, $height > 0 ? str_split($rgba, $width * 4) : []);
    }

    public function png(): string
    {
        return Png::encode($this->width, $this->height, $this->rgba());
    }

    public function rgba(): string
    {
        return implode('', $this->rows);
    }

    /** The pixels of a rectangle (clipped to the picture). */
    public function crop(int $x, int $y, int $width, int $height): self
    {
        $out = self::blank($width, $height);
        $out->draw($this, -$x, -$y, false);

        return $out;
    }

    /** $other drawn at ($x, $y); $blend: its transparent pixels let these show (alpha 0 or 255 only, as pixel art). */
    public function draw(self $other, int $x, int $y, bool $blend = true): void
    {
        $x0 = max(0, $x);
        $x1 = min($this->width, $x + $other->width);
        if ($x1 <= $x0) return;
        for ($sy = max(0, -$y), $ey = min($other->height, $this->height - $y); $sy < $ey; $sy++) {
            $row = substr($other->rows[$sy], ($x0 - $x) * 4, ($x1 - $x0) * 4);
            $at = $x0 * 4;
            $line = &$this->rows[$sy + $y];
            if ($blend) {
                $under = substr($line, $at, strlen($row));
                $mixed = '';
                foreach (str_split($row, 4) as $i => $pixel) $mixed .= ord($pixel[3]) < 128 ? substr($under, $i * 4, 4) : $pixel;
                $row = $mixed;
            }
            $line = substr_replace($line, $row, $at, strlen($row));
            unset($line);
        }
    }

    /** A rectangle of one colour (4 bytes RGBA). */
    public function fill(int $x, int $y, int $width, int $height, string $colour): void
    {
        $x0 = max(0, $x);
        $x1 = min($this->width, $x + $width);
        if ($x1 <= $x0) return;
        $row = str_repeat($colour, $x1 - $x0);
        for ($py = max(0, $y), $ey = min($this->height, $y + $height); $py < $ey; $py++) {
            $this->rows[$py] = substr_replace($this->rows[$py], $row, $x0 * 4, strlen($row));
        }
    }

    /** Smaller by an integer factor: the pixel at the middle of each square (keeps the hard edges of pixel art). */
    public function shrink(int $factor): self
    {
        if ($factor <= 1) return $this;
        $width = intdiv($this->width, $factor);
        $height = intdiv($this->height, $factor);
        $rows = [];
        $half = intdiv($factor, 2);
        for ($y = 0; $y < $height; $y++) {
            $row = $this->rows[$y * $factor + $half];
            $out = '';
            for ($x = 0; $x < $width; $x++) $out .= substr($row, ($x * $factor + $half) * 4, 4);
            $rows[] = $out;
        }

        return new self($width, $height, $rows);
    }

    /** Bigger by an integer factor (each pixel a square). */
    public function grow(int $factor): self
    {
        if ($factor <= 1) return $this;
        $rows = [];
        foreach ($this->rows as $row) {
            $big = '';
            foreach (str_split($row, 4) as $pixel) $big .= str_repeat($pixel, $factor);
            for ($i = 0; $i < $factor; $i++) $rows[] = $big;
        }

        return new self($this->width * $factor, $this->height * $factor, $rows);
    }

    /**
     * Colours replaced (RGB "rrggbb" => RGB "rrggbb"; "" for transparent, on either side), in a rectangle or all
     * over; how many pixels changed.
     */
    public function recolour(array $map, int $x = 0, int $y = 0, ?int $width = null, ?int $height = null): int
    {
        $width ??= $this->width;
        $height ??= $this->height;
        $bytes = [];
        foreach ($map as $from => $to) $bytes[(string) hex2bin($from)] = $to === '' ? "\0\0\0\0" : hex2bin($to) . "\xFF";
        $changed = 0;
        for ($py = max(0, $y), $ey = min($this->height, $y + $height); $py < $ey; $py++) {
            $x0 = max(0, $x);
            $x1 = min($this->width, $x + $width);
            $line = $this->rows[$py];
            for ($px = $x0; $px < $x1; $px++) {
                $at = $px * 4;
                $rgb = ord($line[$at + 3]) < 128 ? '' : substr($line, $at, 3);
                if (isset($bytes[$rgb])) {
                    $line = substr_replace($line, $bytes[$rgb], $at, 4);
                    $changed++;
                }
            }
            $this->rows[$py] = $line;
        }

        return $changed;
    }

    /** @return array<string, int> the colours used ("rrggbb", not the transparent pixels) => how many pixels */
    public function colours(): array
    {
        $count = [];
        foreach (str_split($this->rgba(), 4) as $pixel) {
            if (ord($pixel[3]) < 128) continue;
            $key = bin2hex(substr($pixel, 0, 3));
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
        arsort($count);

        return $count;
    }

    public function isEmpty(): bool
    {
        foreach ($this->rows as $row) for ($i = 3, $n = strlen($row); $i < $n; $i += 4) if (ord($row[$i]) >= 128) return false;

        return true;
    }
}
