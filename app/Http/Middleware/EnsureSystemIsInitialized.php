<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemIsInitialized
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $initialized = $this->isInitialized();

        if (! $initialized && ! $request->is('setup')) {
            return redirect()->route('setup');
        }

        if ($initialized && $request->is('setup')) {
            return auth()->check()
                ? redirect()->route('dashboard')
                : redirect()->route('login');
        }

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        return $request->is(
            'build/*',
            'icons/*',
            'images/*',
            'storage/*',
            'manifest.json',
            'sw.js',
            'favicon.ico',
            'robots.txt',
            'offline',
            'api/*',
            'livewire/*',
            'customer/language/*'
        );
    }

    private function isInitialized(): bool
    {
        try {
            if (! Schema::hasTable('settings')) {
                return true;
            }

            $settingsInitialized = (bool) Setting::query()->value('system_initialized');

            if (! Schema::hasTable('companies')) {
                return $settingsInitialized;
            }

            return $settingsInitialized && Company::query()->exists();
        } catch (\Throwable) {
            return true;
        }
    }
}
