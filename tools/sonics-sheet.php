<?php
// public/img/sonics.png: the Sonic frames of the background (js/sonics.js), one row per colour, 23x32 each.
// php tools/sonics-sheet.php <game>/assets/tileset/sprites public/img/sonics.png   (<game>: sonicGamePhp)
$src = $argv[1]; $out = $argv[2];
$frames = [[246,3],[3,3],[30,3],[57,3],[84,3],[111,3],[138,3],[300,3],[327,3],[354,3],[381,3],[3,38],[30,38],[57,38],[84,38],[273,3],[138,38]];
$colors = ['', '-red', '-green', '-yellow', '-purple', '-orange'];
$im = imagecreatetruecolor(23 * count($frames), 32 * count($colors));
imagesavealpha($im, true); imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
foreach ($colors as $row => $c) {
    $s = imagecreatefrompng("$src/tileset-sonic$c.png");
    foreach ($frames as $i => [$x, $y]) {
        for ($py = 0; $py < 32; $py++) for ($px = 0; $px < 23; $px++) {
            $rgba = imagecolorat($s, $x + $px, $y + $py);
            if (!imageistruecolor($s)) { $t = imagecolorsforindex($s, $rgba); $r=$t['red'];$g=$t['green'];$b=$t['blue'];$a=$t['alpha']; }
            else { $a = ($rgba >> 24) & 127; $r = ($rgba >> 16) & 255; $g = ($rgba >> 8) & 255; $b = $rgba & 255; }
            if ($a === 127 || ($r === 0 && $g === 72 && $b === 0)) continue;
            imagesetpixel($im, $i * 23 + $px, $row * 32 + $py, imagecolorallocatealpha($im, $r, $g, $b, 0));
        }
    }
}
imagepng($im, $out, 9);
