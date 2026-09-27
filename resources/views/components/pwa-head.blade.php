@php
    $branding = app(\App\Services\PwaBrandingService::class);
    $pwaBrand = $branding->brand();
    $manifestUrl = route('pwa.manifest', ['v' => $pwaBrand['version'], ...($branding->customerContext() ? ['portal' => 'customer'] : [])]);
    $icon192 = $branding->iconUrl(192, $pwaBrand);
    $icon512 = $branding->iconUrl(512, $pwaBrand);
@endphp

<link rel="manifest" href="{{ $manifestUrl }}" data-pwa-manifest>
<link rel="icon" type="image/png" sizes="192x192" href="{{ $icon192 }}" data-pwa-icon>
<link rel="icon" type="image/png" sizes="512x512" href="{{ $icon512 }}">
<link rel="apple-touch-icon" sizes="192x192" href="{{ $icon192 }}" data-pwa-apple-icon>
<meta name="theme-color" content="{{ $pwaBrand['theme_color'] }}" data-pwa-theme-color>
<meta name="pwa-brand-version" content="{{ $pwaBrand['version'] }}">
<meta name="pwa-tenant-key" content="{{ $pwaBrand['tenant_key'] }}">
<meta name="description" content="{{ __('messages.welcome_message') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $pwaBrand['name'] }}" data-pwa-app-name>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="application-name" content="{{ $pwaBrand['name'] }}" data-pwa-application-name>
<meta name="msapplication-TileColor" content="{{ $pwaBrand['theme_color'] }}">
<meta name="msapplication-TileImage" content="{{ $icon192 }}">
