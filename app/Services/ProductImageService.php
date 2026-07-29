<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProductImageService
{
    private const MAX_DIMENSION = 1200;

    private const WEBP_QUALITY = 82;

    public function store(UploadedFile $upload, Product $product): string
    {
        $companyId = (int) $product->company_id;

        if ($companyId < 1) {
            throw new RuntimeException('A company is required before storing a product image.');
        }

        [$contents, $extension] = $this->optimizedImage($upload);
        $filename = 'product-'.$product->getKey().'-'.now()->format('YmdHis').'-'.str()->uuid().'.'.$extension;
        $path = "products/{$companyId}/{$filename}";

        if (! Storage::disk('public')->put($path, $contents)) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    public function deleteOwned(?string $path, Product $product): void
    {
        if (! $this->isOwnedPath($path, $product)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    public function isOwnedPath(?string $path, Product $product): bool
    {
        if (blank($path) || ! $product->company_id) {
            return false;
        }

        $expectedDirectory = 'products/'.(int) $product->company_id.'/';

        return str_starts_with($path, $expectedDirectory)
            && ! str_contains($path, '..')
            && basename($path) === substr($path, strlen($expectedDirectory));
    }

    /**
     * @return array{string, string}
     */
    private function optimizedImage(UploadedFile $upload): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to process product images.');
        }

        $sourcePath = $upload->getRealPath();
        $details = @getimagesize($sourcePath);

        if ($details === false || ! isset($details[0], $details[1], $details['mime'])) {
            throw new RuntimeException('The uploaded file is not a readable image.');
        }

        $source = match ($details['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if ($source === false) {
            throw new RuntimeException('The uploaded image format is not supported.');
        }

        try {
            if ($details['mime'] === 'image/jpeg') {
                $source = $this->orientJpeg($source, $sourcePath);
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::MAX_DIMENSION / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($target === false) {
                throw new RuntimeException('The product image could not be resized.');
            }

            try {
                $background = imagecolorallocate($target, 255, 255, 255);
                imagefill($target, 0, 0, $background);
                imagealphablending($target, true);

                if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                    throw new RuntimeException('The product image could not be resized.');
                }

                ob_start();
                $supportsWebp = function_exists('imagewebp');
                $written = $supportsWebp
                    ? imagewebp($target, null, self::WEBP_QUALITY)
                    : imagejpeg($target, null, self::WEBP_QUALITY);
                $contents = ob_get_clean();

                if (! $written || ! is_string($contents) || $contents === '') {
                    throw new RuntimeException('The product image could not be encoded.');
                }

                return [$contents, $supportsWebp ? 'webp' : 'jpg'];
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }
    }

    private function orientJpeg(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
