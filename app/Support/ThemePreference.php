<?php

namespace App\Support;

use App\Models\UserPreference;
use Illuminate\Support\Facades\Schema;

class ThemePreference
{
    public const DEFAULT = 'dark';

    public static function current(): string
    {
        $sessionTheme = session('theme_preference');

        if (self::isValid($sessionTheme)) {
            return $sessionTheme;
        }

        $userContext = self::userContext();

        if ($userContext && self::preferencesTableExists()) {
            $preference = UserPreference::query()
                ->where('guard', $userContext['guard'])
                ->where('user_id', $userContext['user_id'])
                ->where('key', 'theme')
                ->value('value');

            if (self::isValid($preference)) {
                session(['theme_preference' => $preference]);

                return $preference;
            }
        }

        return self::DEFAULT;
    }

    public static function store(string $theme): string
    {
        $theme = self::isValid($theme) ? $theme : self::DEFAULT;

        session(['theme_preference' => $theme]);

        $userContext = self::userContext();

        if ($userContext && self::preferencesTableExists()) {
            UserPreference::query()->updateOrCreate(
                [
                    'guard' => $userContext['guard'],
                    'user_id' => $userContext['user_id'],
                    'key' => 'theme',
                ],
                ['value' => $theme]
            );
        }

        return $theme;
    }

    public static function isValid(mixed $theme): bool
    {
        return in_array($theme, ['dark', 'light'], true);
    }

    private static function userContext(): ?array
    {
        if (auth()->check()) {
            return ['guard' => 'web', 'user_id' => auth()->id()];
        }

        if (auth('customer')->check()) {
            return ['guard' => 'customer', 'user_id' => auth('customer')->id()];
        }

        return null;
    }

    private static function preferencesTableExists(): bool
    {
        try {
            return Schema::hasTable('user_preferences');
        } catch (\Throwable) {
            return false;
        }
    }
}
