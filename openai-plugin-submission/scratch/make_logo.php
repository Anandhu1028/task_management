<?php

// 512x512 TaskFlow logo using GD
$size = 512;
$img = imagecreatetruecolor($size, $size);

$bg = imagecolorallocate($img, 26, 86, 219);   // brand blue
$white = imagecolorallocate($img, 255, 255, 255);

imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $bg);

$cx = 256;
$thick = 14; // half-thickness of line

// Draw 3 chevron layers
$layers = [
    [140, 200],
    [210, 270],
    [280, 340],
];

foreach ($layers as [$yTop, $yBottom]) {
    for ($dx = -$thick; $dx <= $thick; $dx++) {
        for ($dy = -$thick; $dy <= $thick; $dy++) {
            if (($dx * $dx + $dy * $dy) <= ($thick * $thick)) {
                // Left arm of chevron
                imageline($img, $cx - 110 + $dx, $yTop + $dy, $cx + $dx, $yBottom + $dy, $white);
                // Right arm of chevron
                imageline($img, $cx + $dx, $yBottom + $dy, $cx + 110 + $dx, $yTop + $dy, $white);
            }
        }
    }
}

imagepng($img, __DIR__.'/../assets/logo.png');
imagedestroy($img);

echo 'PNG created: '.filesize(__DIR__.'/../assets/logo.png').' bytes'.PHP_EOL;
