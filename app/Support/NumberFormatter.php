<?php

namespace App\Support;

final class NumberFormatter
{
    public static function money(float|int|string|null $value): string
    {
        return number_format(self::normalize($value), 0, '.', ',');
    }

    public static function quantity(float|int|string|null $value, int $maxDecimals = 4): string
    {
        $formatted = number_format(self::normalize($value), $maxDecimals, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private static function normalize(float|int|string|null $value): float
    {
        $number = (float) ($value ?? 0);

        return abs($number) < 0.0000001 ? 0.0 : $number;
    }
}
