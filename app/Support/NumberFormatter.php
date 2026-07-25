<?php

namespace App\Support;

final class NumberFormatter
{
    public static function money(float|int|string|null $value): string
    {
        return number_format(self::normalize($value), 0, '.', ',');
    }

    public static function moneyCompact(float|int|string|null $value): string
    {
        $number = self::normalize($value);
        $decimals = abs($number - round($number)) < 0.0000001 ? 0 : 2;

        return number_format($number, $decimals, '.', ',');
    }

    public static function quantity(float|int|string|null $value, int $maxDecimals = 4): string
    {
        $formatted = number_format(self::normalize($value), $maxDecimals, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private static function normalize(float|int|string|null $value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        $normalized = str_replace([',', ' ', "\u{00A0}"], '', (string) $value);
        $number = is_numeric($normalized) ? (float) $normalized : 0.0;

        return abs($number) < 0.0000001 ? 0.0 : $number;
    }
}
