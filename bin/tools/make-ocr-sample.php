<?php

declare(strict_types=1);

/*
 * Make the sample receipt that Settings' *Test it* reads (Phase 53.1,
 * spec.md §7.27 *Reading text from images*): a made-up fuel receipt, with
 * the total and its amount in two columns, so the layout text shows them
 * kept on one row.
 *
 *   php bin/tools/make-ocr-sample.php <font.ttf> [resources/ocr/sample-receipt.png]
 *
 * Needs GD with FreeType; DejaVu Sans Mono (fonts-dejavu-core) was used.
 */

$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
if (!isset($args[1]) || !is_file($args[1])) {
    fwrite(STDERR, "Usage: php bin/tools/make-ocr-sample.php <font.ttf> [out.png]\n");
    exit(1);
}
$font = $args[1];
$out = $args[2] ?? __DIR__ . '/../../resources/ocr/sample-receipt.png';

$lines = [
    ['MOORSIDE SERVICE STATION', ''],
    ['12 High Street, Kirkby', ''],
    ['', ''],
    ['17/10/2026', '14:32'],
    ['Pump 4', 'Unleaded'],
    ['', ''],
    ['Volume', '32.15 L'],
    ['Price', '149.9p/L'],
    ['', ''],
    ['TOTAL', 'GBP 48.20'],
    ['Paid by card', ''],
    ['', ''],
    ['Thank you for your custom', ''],
];
$size = 26;
$width = 760;
$lineHeight = 52;
$image = imagecreatetruecolor($width, 120 + $lineHeight * count($lines));
if ($image === false) {
    exit(1);
}
imagefill($image, 0, 0, (int) imagecolorallocate($image, 252, 251, 246));
$ink = (int) imagecolorallocate($image, 30, 30, 34);
$y = 80;
foreach ($lines as [$left, $right]) {
    if ($left !== '') {
        imagettftext($image, $size, 0, 50, $y, $ink, $font, $left);
    }
    if ($right !== '') {
        $box = imagettfbbox($size, 0, $font, $right);
        $textWidth = is_array($box) && is_int($box[2]) && is_int($box[0]) ? $box[2] - $box[0] : 0;
        imagettftext($image, $size, 0, $width - 50 - $textWidth, $y, $ink, $font, $right);
    }
    $y += $lineHeight;
}
imagepng($image, $out, 9);
echo "Wrote {$out}\n";
