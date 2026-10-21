<?php

namespace App\Service\Studio;

/**
 * Pixel art as an AI writes and reads it: a palette (one character => a colour) and rows of characters, each
 * character a pixel (or a square of `scale` x `scale` pixels). Or a PNG in base64 of the exact size.
 *
 *     {"palette": {".": "transparent", "g": "#3a9d23", "d": "#1e5c12"}, "scale": 2, "rows": ["gggg...", ...]}
 */
final class Pixels
{
    /** The characters given to the colours when a picture is turned into text ('.' is transparent). */
    private const CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#@$%&*+=-~:;!?<>^/|()[]{}';
    public const MAX_PALETTE = 64;

    /** A picture of exactly $width x $height pixels from what the AI sent; ToolError if it is not right. */
    public static function parse(mixed $art, int $width, int $height, string $what = 'pixels'): Canvas
    {
        if (!is_array($art)) throw new ToolError("$what: an object {palette, rows, scale} or {png} is expected.");
        if (isset($art['png'])) {
            $png = is_string($art['png']) ? base64_decode($art['png'], true) : false;
            $canvas = $png !== false && strlen($png) <= 2 * 1024 * 1024 ? Canvas::fromPng($png) : null;
            if ($canvas === null) throw new ToolError("$what.png: not a PNG in base64 (8 bits a channel, RGB or RGBA, not interlaced).");
            if ($canvas->width !== $width || $canvas->height !== $height) {
                throw new ToolError("$what.png: {$canvas->width}x{$canvas->height} pixels, $width x $height expected.");
            }

            return $canvas;
        }
        $scale = $art['scale'] ?? 1;
        if (!in_array($scale, [1, 2, 4, 8], true) || $width % $scale !== 0 || $height % $scale !== 0) {
            throw new ToolError("$what.scale: 1, 2, 4 or 8, dividing $width x $height.");
        }
        $palette = $art['palette'] ?? null;
        if (!is_array($palette) || $palette === [] || count($palette) > self::MAX_PALETTE) {
            throw new ToolError("$what.palette: 1 to " . self::MAX_PALETTE . ' characters => colours.');
        }
        $colours = [];
        foreach ($palette as $char => $colour) {
            $char = (string) $char;
            if (mb_strlen($char) !== 1) throw new ToolError("$what.palette: each key is one character (\"$char\").");
            $colours[$char] = self::colour($colour, "$what.palette[\"$char\"]");
        }
        $rows = $art['rows'] ?? null;
        $w = intdiv($width, $scale);
        $h = intdiv($height, $scale);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== $h) throw new ToolError("$what.rows: $h strings expected (scale $scale).");
        $rgba = '';
        foreach ($rows as $y => $row) {
            if (!is_string($row) || mb_strlen($row) !== $w) throw new ToolError("$what.rows[$y]: $w characters expected (scale $scale).");
            $line = '';
            foreach (mb_str_split($row) as $x => $char) {
                if (!isset($colours[$char])) throw new ToolError("$what.rows[$y][$x]: \"$char\" is not in the palette.");
                $line .= str_repeat($colours[$char], $scale);
            }
            $rgba .= str_repeat($line, $scale);
        }

        return Canvas::fromRgba($width, $height, $rgba);
    }

    /**
     * A picture as text: {palette, rows} (scale 1); null if it has more colours than characters. Alpha below 128:
     * transparent ('.').
     */
    public static function text(Canvas $canvas): ?array
    {
        $chars = [];
        $palette = ['.' => 'transparent'];
        $rows = [];
        $rgba = $canvas->rgba();
        for ($y = 0; $y < $canvas->height; $y++) {
            $row = '';
            foreach (str_split(substr($rgba, $y * $canvas->width * 4, $canvas->width * 4), 4) as $pixel) {
                if (ord($pixel[3]) < 128) {
                    $row .= '.';
                    continue;
                }
                $hex = bin2hex(substr($pixel, 0, 3));
                if (!isset($chars[$hex])) {
                    if (count($chars) >= strlen(self::CHARS)) return null;
                    $chars[$hex] = self::CHARS[count($chars)];
                    $palette[$chars[$hex]] = '#' . $hex;
                }
                $row .= $chars[$hex];
            }
            $rows[] = $row;
        }

        return ['palette' => $palette, 'rows' => $rows, 'scale' => 1];
    }

    /** "#rgb", "#rrggbb", "#rrggbbaa" or "transparent" (or null) => 4 bytes RGBA. */
    public static function colour(mixed $colour, string $what): string
    {
        if ($colour === null || $colour === 'transparent') return "\0\0\0\0";
        if (!is_string($colour) || !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $colour, $m)) {
            throw new ToolError("$what: a colour \"#rrggbb\" (or \"#rgb\", \"#rrggbbaa\", \"transparent\") is expected.");
        }
        $hex = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];
        $bytes = (string) hex2bin(strlen($hex) === 6 ? $hex . 'ff' : $hex);

        // pixel art: a pixel is there or not
        return substr($bytes, 0, 3) . (ord($bytes[3]) >= 128 ? "\xFF" : "\0");
    }

    /** "#rrggbb" => "rrggbb" (for Canvas::recolour; $transparent: "transparent" => ""); ToolError if it is not one. */
    public static function rgb(mixed $colour, string $what, bool $transparent = false): string
    {
        if ($transparent && $colour === 'transparent') return '';
        if (!is_string($colour) || !preg_match('/^#?([0-9a-f]{6})$/i', $colour, $m)) throw new ToolError("$what: a colour \"#rrggbb\" is expected.");

        return strtolower($m[1]);
    }
}
