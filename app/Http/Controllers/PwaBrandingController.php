<?php

namespace App\Http\Controllers;

use App\Services\PwaBrandingService;
use Illuminate\Http\Response;

class PwaBrandingController extends Controller
{
    public function manifest(PwaBrandingService $branding): Response
    {
        return response(json_encode($branding->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie, Host',
            'X-Content-Type-Options' => 'nosniff',
            'X-PWA-Brand-Version' => $branding->brand()['version'],
        ]);
    }

    public function icon(int $size, PwaBrandingService $branding): Response
    {
        abort_unless(in_array($size, [192, 512], true), 404);

        return response($branding->icon($size), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Vary' => 'Cookie, Host',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
