<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProductAvatar
{
    public static function wordInitials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $meaningful = [];

        foreach ($words as $word) {
            if (preg_match_all('/[\p{L}\p{N}]/u', $word, $matches) < 1) {
                continue;
            }

            $cleanWord = implode('', $matches[0]);
            if (preg_match('/^\p{L}/u', $cleanWord) === 1) {
                $meaningful[] = $cleanWord;
            }
        }

        if ($meaningful === []) {
            return 'PR';
        }

        $first = mb_substr($meaningful[0], 0, 1);
        $last = count($meaningful) > 1
            ? mb_substr($meaningful[array_key_last($meaningful)], 0, 1)
            : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * @return array{initials: string, classes: string}
     */
    public static function for(?string $name, int|string|null $productId = null): array
    {
        $normalizedName = trim((string) $name);
        $words = preg_split('/\s+/u', $normalizedName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $meaningful = [];
        $removedTrailingDimension = false;

        foreach ($words as $word) {
            if (preg_match('/^\d+(?:[.,]\d+)?[x×]\d+(?:[.,]\d+)?(?:\p{L}+)?$/iu', $word) === 1) {
                $removedTrailingDimension = true;

                continue;
            }

            if (preg_match_all('/[\p{L}\p{N}]/u', $word, $matches) > 0) {
                $meaningful[] = implode('', $matches[0]);
            }
        }

        if ($meaningful === []) {
            $initials = 'PR';
        } else {
            $firstWord = $meaningful[0];
            $lastWord = $meaningful[array_key_last($meaningful)];

            // A trailing dimension describes the preceding product word rather than
            // being part of its name (for example, "Tiles 40x40").
            if ($removedTrailingDimension && preg_match('/^\p{L}+$/u', $lastWord) === 1) {
                $lastWord = Str::singular($lastWord);
            }

            $first = mb_substr($firstWord, 0, 1);
            $last = mb_substr($lastWord, -1, 1);
            $initials = mb_strtoupper($first.($last !== $first || mb_strlen($firstWord) > 1 ? $last : ''));
        }

        $palettes = [
            'bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-950 dark:text-sky-200 dark:ring-sky-800',
            'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-800',
            'bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-950 dark:text-violet-200 dark:ring-violet-800',
            'bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
            'bg-rose-100 text-rose-800 ring-rose-200 dark:bg-rose-950 dark:text-rose-200 dark:ring-rose-800',
            'bg-cyan-100 text-cyan-900 ring-cyan-200 dark:bg-cyan-950 dark:text-cyan-200 dark:ring-cyan-800',
        ];
        $seed = filled($productId) ? 'product:'.$productId : mb_strtolower($normalizedName ?: 'product');
        $palette = $palettes[((int) sprintf('%u', crc32($seed))) % count($palettes)];

        return ['initials' => $initials, 'classes' => $palette];
    }
}
