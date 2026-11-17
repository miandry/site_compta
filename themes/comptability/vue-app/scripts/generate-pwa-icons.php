<?php

/**
 * @file
 * Generate maskable / apple / favicon PNGs for the Comptabilité PWA.
 * Usage: php generate-pwa-icons.php /path/to/output-dir
 */

declare(strict_types=1);

$out = $argv[1] ?? (__DIR__ . '/../pwa/icons');
if (!is_dir($out) && !mkdir($out, 0755, TRUE)) {
  fwrite(STDERR, "Cannot create $out\n");
  exit(1);
}

/**
 * Soft neumorphism tile with a red "C" mark (safe zone ~80% for maskable).
 */
function paint_icon(int $size, bool $maskable_pad = TRUE): GdImage {
  $im = imagecreatetruecolor($size, $size);
  imagesavealpha($im, TRUE);
  $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
  imagefill($im, 0, 0, $transparent);

  $bg = imagecolorallocate($im, 224, 229, 236); // #e0e5ec
  $accent = imagecolorallocate($im, 239, 68, 68); // #ef4444
  $ink = imagecolorallocate($im, 55, 65, 81); // #374151
  $white = imagecolorallocate($im, 255, 255, 255);

  // Full-bleed background (maskable icons need edge color).
  imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $bg);

  $pad = $maskable_pad ? (int) round($size * 0.12) : (int) round($size * 0.08);
  $inner = $size - 2 * $pad;
  $cx = (int) round($size / 2);
  $cy = (int) round($size / 2);
  $r = (int) round($inner / 2);

  // Soft circle plate.
  imagefilledellipse($im, $cx, $cy, $inner, $inner, $white);
  imageellipse($im, $cx, $cy, $inner, $inner, imagecolorallocate($im, 163, 177, 198));

  // Accent ring.
  $ring = (int) max(2, round($size * 0.035));
  for ($i = 0; $i < $ring; $i++) {
    imageellipse($im, $cx, $cy, $inner - 4 - 2 * $i, $inner - 4 - 2 * $i, $accent);
  }

  // Letter C via arcs (no TTF dependency).
  $font = (int) max(5, round($size * 0.42));
  // Approximate with thick arc using many ellipses clipped conceptually.
  $outer = (int) round($inner * 0.62);
  $inner_arc = (int) round($outer * 0.55);
  $thickness = (int) max(3, round($size * 0.07));
  for ($a = 40; $a <= 320; $a += 2) {
    $rad = deg2rad($a);
    for ($t = 0; $t < $thickness; $t++) {
      $rr = ($outer / 2) - $t;
      $x = (int) round($cx + cos($rad) * $rr);
      $y = (int) round($cy + sin($rad) * $rr);
      imagesetpixel($im, $x, $y, $ink);
      // Thicken.
      if ($x + 1 < $size) imagesetpixel($im, $x + 1, $y, $ink);
      if ($y + 1 < $size) imagesetpixel($im, $x, $y + 1, $ink);
    }
  }
  // Fill the C stroke more solidly with overlapping ellipses difference is hard in GD;
  // draw a second thicker arc band.
  for ($a = 45; $a <= 315; $a++) {
    $rad = deg2rad($a);
    for ($t = 0; $t < $thickness; $t++) {
      $rr = ($outer / 2) - $t - 1;
      $x = (int) round($cx + cos($rad) * $rr);
      $y = (int) round($cy + sin($rad) * $rr);
      imagefilledellipse($im, $x, $y, max(2, (int) ($size * 0.02)), max(2, (int) ($size * 0.02)), $ink);
    }
  }

  return $im;
}

function write_png(GdImage $im, string $path): void {
  if (!imagepng($im, $path, 6)) {
    fwrite(STDERR, "Failed $path\n");
    exit(1);
  }
  imagedestroy($im);
  echo "wrote $path\n";
}

write_png(paint_icon(192, TRUE), "$out/manifest-icon-192.maskable.png");
write_png(paint_icon(512, TRUE), "$out/manifest-icon-512.maskable.png");
write_png(paint_icon(180, FALSE), "$out/apple-icon-180.png");
write_png(paint_icon(196, FALSE), "$out/favicon-196.png");
