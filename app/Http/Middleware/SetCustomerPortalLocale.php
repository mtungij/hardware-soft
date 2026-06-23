<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetCustomerPortalLocale
{
    private const SUPPORTED_LOCALES = ['sw', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('customer_locale')
            ?: auth('customer')->user()?->preferred_locale
            ?: config('app.locale', 'sw');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'sw';
        }

        App::setLocale($locale);
        $request->session()->put('customer_locale', $locale);

        return $next($request);
    }
}
