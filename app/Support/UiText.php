<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class UiText
{
    public static function translate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $key = 'messages.ui.'.self::key($value);

        return Lang::has($key) ? __($key) : $value;
    }

    private static function key(string $value): string
    {
        return (string) Str::of($value)
            ->replace('&', ' and ')
            ->replace(["'", '’'], '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }
}
