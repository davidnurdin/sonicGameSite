<?php
// public/img/clouds.png: the clouds of Green Hill act 1 (the sky blocks of level1), on a transparent background.
// php tools/clouds.php <game>/assets/levels/level1.bin <game>/assets/tileset/levels/tileset1.png public/img/clouds.png
[$_, $bin, $tiles, $out] = $argv;
$m = file_get_contents($bin); $fw = 256; $rows = 6;
$t = imagecreatefrompng($tiles);
$im = imagecreate($fw * 32, $rows * 32);                       // palette: transparent, then the cloud colours
imagecolortransparent($im, imagecolorallocate($im, 0, 170, 255));
$keep = [0xffffff => imagecolorallocate($im, 255, 255, 255), 0x55ffff => imagecolorallocate($im, 85, 255, 255)];
$minY = PHP_INT_MAX; $maxY = 0;
for ($y = 0; $y < $rows; $y++) for ($x = 0; $x < $fw; $x++) {
    $b = ord($m[$y * $fw + $x]);
    if ($b >= 0x79 && $b <= 0x7B) continue;                    // ring blocks
    for ($py = 0; $py < 32; $py++) for ($px = 0; $px < 32; $px++) {
        $c = imagecolorat($t, $b * 32 + $px, $py) & 0xffffff;
        if (!isset($keep[$c])) continue;
        imagesetpixel($im, $x * 32 + $px, $y * 32 + $py, $keep[$c]);
        $minY = min($minY, $y * 32 + $py); $maxY = max($maxY, $y * 32 + $py);
    }
}
$crop = imagecreate($fw * 32, $maxY - $minY + 1);
imagecolortransparent($crop, imagecolorallocate($crop, 0, 170, 255));
imagecopy($crop, $im, 0, 0, 0, $minY, $fw * 32, $maxY - $minY + 1);
imagepng($crop, $out, 9);
