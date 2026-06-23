<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\LivewireManager as VoltLivewireManager;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('register', 'auth.register')
        ->name('register');

    Route::get('login', function () {
        $customerHost = parse_url(config('app.customer_portal_url', env('CUSTOMER_PORTAL_URL', '')), PHP_URL_HOST);

        if ($customerHost && request()->getHost() === $customerHost) {
            return response('', 302)->header('Location', route('customer.login', [], false));
        }

        $container = Container::getInstance();

        return $container->call([
            $container->make(VoltLivewireManager::class)->new('auth.login'),
            '__invoke',
        ]);
    })->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    Route::post('logout', function (Logout $logout) {
        $logout();

        return redirect('/');
    })->name('logout');
});
