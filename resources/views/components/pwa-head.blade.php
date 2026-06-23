@php
    $isCustomerPortal = request()->getHost() === parse_url(config('app.customer_portal_url', env('CUSTOMER_PORTAL_URL', '')), PHP_URL_HOST);
    try {
        $pwaName = \Illuminate\Support\Facades\Schema::hasTable('settings')
            ? \App\Models\Setting::query()->value('company_name')
            : null;
    } catch (\Throwable) {
        $pwaName = null;
    }

    $pwaName = $pwaName ?: \App\Models\Company::current()?->company_name;
    $pwaName = $pwaName ?: config('app.name', 'Hardex');
@endphp

<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" type="image/png" sizes="72x72" href="{{ asset('icons/icon-72x72.png') }}">
<link rel="icon" type="image/png" sizes="96x96" href="{{ asset('icons/icon-96x96.png') }}">
<link rel="icon" type="image/png" sizes="128x128" href="{{ asset('icons/icon-128x128.png') }}">
<link rel="icon" type="image/png" sizes="144x144" href="{{ asset('icons/icon-144x144.png') }}">
<link rel="icon" type="image/png" sizes="152x152" href="{{ asset('icons/icon-152x152.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192x192.png') }}">
<link rel="icon" type="image/png" sizes="384x384" href="{{ asset('icons/icon-384x384.png') }}">
<link rel="icon" type="image/png" sizes="512x512" href="{{ asset('icons/icon-512x512.png') }}">
<link rel="apple-touch-icon" sizes="152x152" href="{{ asset('icons/icon-152x152.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/icon-192x192.png') }}">
<link rel="apple-touch-startup-image" href="{{ asset('icons/icon-512x512.png') }}">
<meta name="theme-color" content="#f97316">
<meta name="description" content="{{ __('messages.welcome_message') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $pwaName }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="application-name" content="{{ $pwaName }}">
<meta name="msapplication-TileColor" content="#f97316">
<meta name="msapplication-TileImage" content="{{ asset('icons/icon-144x144.png') }}">
