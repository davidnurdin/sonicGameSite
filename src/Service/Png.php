<?php

namespace App\Service;

/**
 * The pixels of a PNG as the editors save them (8 bits a channel, RGBA or RGB, not interlaced), read by hand: the
 * image of the site has no GD. Enough to tell which blocks of a set of tiles changed between two versions.
 */
final class Png
{
    /** @return array{0: int, 1: int, 2: string}|null width, height, RGBA bytes (4 a pixel) */
    public static function rgba(string $png): ?array
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) return null;
        $pos = 8;
        $idat = '';
        $width = $height = $type = 0;
        while ($pos + 8 <= strlen($png)) {
            $length = unpack('N', substr($png, $pos, 4))[1];
            $chunk = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $length);
            $pos += 12 + $length;
            if ($chunk === 'IHDR') {
                $header = unpack('Nw/Nh/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
                [$width, $height, $type] = [$header['w'], $header['h'], $header['type']];
                if ($header['depth'] !== 8 || $header['interlace'] !== 0 || !in_array($type, [2, 6], true)) return null;
            } elseif ($chunk === 'IDAT') {
                $idat .= $data;
            } elseif ($chunk === 'IEND') {
                break;
            }
        }
        if ($width < 1 || $height < 1 || $width * $height > 4096 * 4096) return null;
        $raw = @gzuncompress($idat);
        $bpp = $type === 6 ? 4 : 3;
        $stride = $width * $bpp;
        if (!is_string($raw) || strlen($raw) !== ($stride + 1) * $height) return null;

        // the filters of each row (PNG spec, 9.2)
        $out = '';
        $previous = str_repeat("\0", $stride);
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$y * ($stride + 1)]);
            $row = substr($raw, $y * ($stride + 1) + 1, $stride);
            if ($filter !== 0) {
                $line = array_values(unpack('C*', $row));
                $up = array_values(unpack('C*', $previous));
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                    $b = $up[$i];
                    $c = $i >= $bpp ? $up[$i - $bpp] : 0;
                    $line[$i] = ($line[$i] + match ($filter) {
                        1 => $a,
                        2 => $b,
                        3 => intdiv($a + $b, 2),
                        4 => self::paeth($a, $b, $c),
                        default => 0,
                    }) & 0xFF;
                }
                $row = pack('C*', ...$line);
            }
            $previous = $row;
            $out .= $bpp === 4 ? $row : implode('', array_map(fn(string $p) => $p . "\xFF", str_split($row, 3)));
        }

        return [$width, $height, $out];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        [$pa, $pb, $pc] = [abs($p - $a), abs($p - $b), abs($p - $c)];

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}
