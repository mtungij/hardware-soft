<?php

use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\CustomerOrderingController;
use App\Http\Controllers\Api\CustomerPortalApiController;
use App\Http\Middleware\SetCustomerApiLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('customer')->middleware([SetCustomerApiLocale::class, 'throttle:customer-api'])->group(function () {
    Route::post('login', [CustomerAuthController::class, 'login'])->name('api.customer.login');
    Route::post('register', [CustomerAuthController::class, 'register'])->name('api.customer.register');
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->name('api.customer.forgot-password');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('logout', [CustomerAuthController::class, 'logout'])->name('api.customer.logout');
        Route::post('change-password', [CustomerAuthController::class, 'changePassword'])->name('api.customer.change-password');

        Route::middleware('customer.api.active')->group(function () {
            Route::get('products', [CustomerOrderingController::class, 'products'])->name('api.customer.products');
            Route::get('requests', [CustomerOrderingController::class, 'requests']);
            Route::post('requests', [CustomerOrderingController::class, 'store']);
            Route::get('quotations', [CustomerOrderingController::class, 'quotations']);
            Route::post('quotations/{quotation}/decision', [CustomerOrderingController::class, 'decide']);
            Route::get('dashboard', [CustomerPortalApiController::class, 'dashboard'])->name('api.customer.dashboard');
            Route::get('debts', [CustomerPortalApiController::class, 'debts'])->name('api.customer.debts');
            Route::get('debts/{sale}', [CustomerPortalApiController::class, 'debt'])->name('api.customer.debts.show');
            Route::get('receipts', [CustomerPortalApiController::class, 'receipts'])->name('api.customer.receipts');
            Route::post('receipts', [CustomerPortalApiController::class, 'storeReceipt'])->name('api.customer.receipts.store');
            Route::get('deposits', [CustomerPortalApiController::class, 'deposits'])->name('api.customer.deposits');
            Route::post('deposits', [CustomerPortalApiController::class, 'storeDeposit'])->name('api.customer.deposits.store');
            Route::get('statements', [CustomerPortalApiController::class, 'statements'])->name('api.customer.statements');
            Route::get('profile', [CustomerPortalApiController::class, 'profile'])->name('api.customer.profile');
            Route::put('profile', [CustomerPortalApiController::class, 'updateProfile'])->name('api.customer.profile.update');
            Route::get('notifications', [CustomerPortalApiController::class, 'notifications'])->name('api.customer.notifications');
        });
    });
});
