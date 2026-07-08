<?php

namespace App\Http\Middleware;

use App\Models\UserPreference;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SetStaffLocale
{
    private const SUPPORTED_LOCALES = ['en', 'sw'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('customer/*')) {
            return $next($request);
        }

        $locale = $request->session()->get('staff_locale')
            ?: $this->storedPreference()
            ?: $request->cookie('hardex_staff_locale')
            ?: $this->companyPreference()
            ?: config('app.locale', 'en');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'sw';
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);
        $request->session()->put('staff_locale', $locale);

        return $next($request);
    }

    private function storedPreference(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        try {
            if (! Schema::hasTable('user_preferences')) {
                return null;
            }

            return UserPreference::query()
                ->where('guard', 'web')
                ->where('user_id', auth()->id())
                ->where('key', 'locale')
                ->value('value');
        } catch (\Throwable) {
            return null;
        }
    }

    private function companyPreference(): ?string
    {
        try {
            if (Schema::hasTable('companies')) {
                $language = \App\Models\Company::current()?->language;

                if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                    return $language;
                }
            }

            if (Schema::hasTable('settings')) {
                $language = \App\Models\Setting::query()->value('language');

                if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                    return $language;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
