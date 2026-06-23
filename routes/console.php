<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pwa:icons', function () {
    $source = public_path('images/hardex.png');
    $targetDirectory = public_path('icons');
    $sizes = [72, 96, 128, 144, 152, 192, 384, 512];

    if (! extension_loaded('gd')) {
        $this->error('The PHP GD extension is required to generate PWA icons.');

        return 1;
    }

    if (! file_exists($source)) {
        $this->error("Logo not found at {$source}.");

        return 1;
    }

    if (! is_dir($targetDirectory)) {
        mkdir($targetDirectory, 0755, true);
    }

    $image = imagecreatefrompng($source);

    foreach ($sizes as $size) {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));
        imagepng($canvas, public_path("icons/icon-{$size}x{$size}.png"));
        imagedestroy($canvas);
    }

    imagedestroy($image);

    $this->info('Hardex PWA icons generated successfully.');

    return 0;
})->purpose('Generate Hardex PWA icons from public/images/hardex.png');
