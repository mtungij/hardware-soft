<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class UiText
{
    private static ?array $phrases = null;

    public static function translate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        if (Lang::has($value, app()->getLocale(), false)) {
            return __($value);
        }

        if (self::$phrases === null) {
            self::$phrases = [];
            foreach (['en', 'sw'] as $locale) {
                foreach (['application', 'terminology'] as $group) {
                    foreach (Lang::get($group, [], $locale, false) as $key => $phrase) {
                        self::$phrases[$phrase] ??= $group.'.'.$key;
                    }
                }
            }
            // Common action words can have several meanings in the catalogs.
            foreach (['Save', 'Cancel', 'Delete', 'Search', 'Print', 'Login', 'Logout', 'Product', 'Customer', 'Supplier', 'Quantity'] as $action) {
                $key = self::$phrases[$action] ?? null;
                if ($key) {
                    self::$phrases[Lang::get($key, [], 'sw', false)] = $key;
                }
            }
        }

        if (isset(self::$phrases[$value])) {
            return __(self::$phrases[$value]);
        }

        $key = self::key($value);
        $messageKey = 'messages.ui.'.$key;
        $commonKey = 'common.'.$key;

        if (Lang::has($messageKey, app()->getLocale(), false)) {
            return __($messageKey);
        }

        if (Lang::has($commonKey, app()->getLocale(), false)) {
            return __($commonKey);
        }

        $translated = __($value);

        return $translated === $value ? $value : $translated;
    }

    /** Translate system codes only. Never pass customer or product names here. */
    public static function status(?string $code, ?string $locale = null): string
    {
        $key = 'statuses.'.str_replace([' ', '-'], '_', strtolower(trim((string) $code)));

        return Lang::has($key, $locale, false)
            ? Lang::get($key, [], $locale, false)
            : Lang::get('statuses.unknown', [], $locale, false);
    }

    public static function label(mixed $code): mixed
    {
        if (is_string($code) && Lang::has('statuses.'.$code, app()->getLocale(), false)) {
            return self::status($code);
        }

        return is_string($code) ? self::translate(Str::headline($code)) : $code;
    }

    public static function notificationType(string $code): string
    {
        $key = 'notification_types.'.$code;

        return Lang::has($key, app()->getLocale(), false) ? __($key) : __('notification_types.notification');
    }

    public static function permission(string $name): string
    {
        return RolePermissionCatalog::label($name);
    }

    public static function withLocale(string $locale, callable $callback): mixed
    {
        $original = app()->getLocale();
        try {
            app()->setLocale(in_array($locale, ['en', 'sw'], true) ? $locale : 'en');

            return $callback();
        } finally {
            app()->setLocale($original);
        }
    }

    /** Localize stored system feedback without rewriting the original diagnostic record. */
    public static function feedback(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '-';
        }

        $translated = self::translate($value);
        if ($translated !== $value || isset(self::$phrases[$value])) {
            return $translated;
        }

        foreach (['en', 'sw'] as $locale) {
            foreach (['feedback', 'portal_notifications', 'application', 'terminology'] as $group) {
                foreach (Lang::get($group, [], $locale, false) as $key => $phrase) {
                    if (! is_string($phrase) || ! preg_match('/:[a-zA-Z_]+/', $phrase)) {
                        continue;
                    }
                    $parts = preg_split('/(:[a-zA-Z_]+)/', $phrase, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $pattern = '';
                    $names = [];
                    foreach ($parts as $index => $part) {
                        if ($index % 2) {
                            $names[] = substr($part, 1);
                            $pattern .= '(.*?)';
                        } else {
                            $pattern .= preg_quote($part, '~');
                        }
                    }
                    if (preg_match('~^'.$pattern.'$~su', $value, $matches)) {
                        return __($group.'.'.$key, array_combine($names, array_slice($matches, 1)));
                    }
                }
            }
        }

        return __('feedback.system_error');
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
