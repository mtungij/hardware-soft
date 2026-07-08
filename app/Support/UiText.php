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

        if (Lang::has($value) || Lang::has($value, app()->getLocale(), false)) {
            return __($value);
        }

        $key = self::key($value);
        $messageKey = 'messages.ui.'.$key;
        $commonKey = 'common.'.$key;

        if (Lang::has($messageKey)) {
            return __($messageKey);
        }

        if (Lang::has($commonKey)) {
            return __($commonKey);
        }

        $translated = __($value);

        return $translated === $value ? $value : $translated;
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
