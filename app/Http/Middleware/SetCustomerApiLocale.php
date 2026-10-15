<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCustomerApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = auth('sanctum')->user();
        $locale = $request->header('X-Locale')
            ?: $request->cookie('hardex_customer_locale')
            ?: $account?->preferred_locale
            ?: config('app.locale', 'en');
        $locale = in_array($locale, ['en', 'sw'], true) ? $locale : 'en';
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
