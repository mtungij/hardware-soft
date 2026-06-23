<?php

use App\Http\Controllers\CustomerPortal\CustomerFileDownloadController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\PurchaseOrderPdfController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('pwa/manifest.json', function () {
    $isCustomerPortal = request()->getHost() === parse_url(config('app.customer_portal_url', env('CUSTOMER_PORTAL_URL', '')), PHP_URL_HOST);
    try {
        $companyName = \Illuminate\Support\Facades\Schema::hasTable('settings')
            ? \App\Models\Setting::query()->value('company_name')
            : null;
    } catch (\Throwable) {
        $companyName = null;
    }

    $companyName = $companyName ?: \App\Models\Company::current()?->company_name;
    $name = $companyName ?: config('app.name', 'Hardex');
    $shortName = \Illuminate\Support\Str::of($name)->squish()->limit(12, '')->value();
    $description = $isCustomerPortal
        ? 'Customer portal for checking debts, deposits, receipts, payments, and account statements.'
        : 'Staff workspace for inventory, sales, accounting, reporting, and administration.';
    $startUrl = $isCustomerPortal ? '/customer/login' : '/login';

    return response()->json([
        'name' => $name,
        'short_name' => $shortName ?: 'Hardex',
        'description' => $description,
        'theme_color' => '#06b6d4',
        'background_color' => '#ffffff',
        'display' => 'standalone',
        'orientation' => 'portrait',
        'start_url' => $startUrl,
        'scope' => '/',
        'id' => $isCustomerPortal ? '/customer' : '/staff',
        'categories' => ['business', 'productivity', 'finance'],
        'icons' => collect([72, 96, 128, 144, 152, 192, 384, 512])->map(fn (int $size) => [
            'src' => "/pwa/icons/icon-{$size}x{$size}.png",
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ])->values(),
    ]);
})->name('pwa.manifest');

Route::get('pwa/icons/{filename}', function (string $filename) {
    abort_unless(preg_match('/^icon-(72|96|128|144|152|192|384|512)x\1\.png$/', $filename), 404);

    $path = public_path("icons/{$filename}");
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->name('pwa.icon');

Route::post('theme-preference', function (Request $request) {
    $data = $request->validate([
        'theme' => ['required', 'in:dark,light'],
    ]);

    return response()->json([
        'theme' => \App\Support\ThemePreference::store($data['theme']),
    ]);
})->name('theme.preference');

Route::post('staff/language/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, ['en', 'sw'], true), 404);

    $request->session()->put('staff_locale', $locale);

    if (auth()->check()) {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('user_preferences')) {
                \App\Models\UserPreference::query()->updateOrCreate(
                    [
                        'guard' => 'web',
                        'user_id' => auth()->id(),
                        'key' => 'locale',
                    ],
                    ['value' => $locale]
                );
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    return back();
})->name('staff.language');

Route::get('/', function () {
    if (request()->getHost() === parse_url(config('app.customer_portal_url', env('CUSTOMER_PORTAL_URL', '')), PHP_URL_HOST)) {
        return auth('customer')->check()
            ? redirect()->route('customer.dashboard')
            : redirect()->route('customer.login');
    }

    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::view('offline', 'offline')->name('offline');
Volt::route('setup', 'setup.index')->name('setup');

Route::post('customer/language/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, ['sw', 'en'], true), 404);

    $request->session()->put('customer_locale', $locale);

    if ($account = Auth::guard('customer')->user()) {
        $account->forceFill(['preferred_locale' => $locale])->save();
    }

    return back();
})->name('customer.language');

Route::middleware('customer.locale')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Volt::route('customer/login', 'customer.auth.login')->name('customer.login');
        Volt::route('customer/register', 'customer.auth.register')->name('customer.register');
    });

    Route::middleware('auth:customer')->group(function () {
        Route::post('customer/logout', function () {
            Auth::guard('customer')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('customer.login');
        })->name('customer.logout');

        Volt::route('customer/pending', 'customer.pending')->name('customer.pending');

        Route::middleware('customer.active')->group(function () {
            Volt::route('customer/dashboard', 'customer.dashboard')->name('customer.dashboard');
            Volt::route('customer/debts', 'customer.debts.index')->name('customer.debts.index');
            Volt::route('customer/debts/{sale}', 'customer.debts.show')->name('customer.debts.show');
            Volt::route('customer/receipts', 'customer.receipts.index')->name('customer.receipts.index');
            Volt::route('customer/receipts/create', 'customer.receipts.create')->name('customer.receipts.create');
            Route::get('customer/receipts/{receipt}/download', [CustomerFileDownloadController::class, 'receipt'])->name('customer.receipts.download');
            Volt::route('customer/deposits', 'customer.deposits.index')->name('customer.deposits.index');
            Volt::route('customer/deposits/create', 'customer.deposits.create')->name('customer.deposits.create');
            Route::get('customer/deposits/{deposit}/download', [CustomerFileDownloadController::class, 'deposit'])->name('customer.deposits.download');
            Volt::route('customer/statement', 'customer.statement')->name('customer.statement');
            Volt::route('customer/notifications', 'customer.notifications.index')->name('customer.notifications.index');
            Volt::route('customer/profile', 'customer.profile')->name('customer.profile');
        });
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');

    Route::view('profile', 'profile')->name('profile');

    Route::middleware('role.any:Super Admin,Admin')->group(function () {
        Volt::route('users', 'users.index')->name('users.index');
        Volt::route('users/create', 'users.create')->name('users.create');
        Volt::route('users/{user}/edit', 'users.edit')->name('users.edit');

        Volt::route('roles', 'roles.index')->name('roles.index');
        Volt::route('settings', 'settings.index')->name('settings.index');
        Volt::route('settings/company', 'settings.company')->name('settings.company');

        Volt::route('products/create', 'products.create')->name('products.create');
        Volt::route('products/{product}/edit', 'products.edit')->name('products.edit');
        Volt::route('suppliers/create', 'suppliers.create')->name('suppliers.create');
        Volt::route('suppliers/{supplier}/edit', 'suppliers.edit')->name('suppliers.edit');
        Volt::route('customers/create', 'customers.create')->name('customers.create');
        Volt::route('customers/{customer}/edit', 'customers.edit')->name('customers.edit');

        Volt::route('purchases/{purchase}/edit', 'purchases.edit')->name('purchases.edit');
        Volt::route('stock-adjustments/approve', 'stock-adjustments.approve')->name('stock-adjustments.approve');
        Volt::route('stock-transfers/{stockTransfer}/edit', 'stock-transfers.edit')->name('stock-transfers.edit');
        Volt::route('sales/{sale}/cancel', 'sales.cancel')->name('sales.cancel');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper')->group(function () {
        Volt::route('purchases/create', 'purchases.create')->name('purchases.create');
        Volt::route('purchases/{purchase}/receive', 'purchases.receive')->name('purchases.receive');
        Route::get('purchases/{purchase}/purchase-order-pdf', PurchaseOrderPdfController::class)->name('purchases.purchase-order-pdf');
        Volt::route('stock-adjustments/create', 'stock-adjustments.create')->name('stock-adjustments.create');
        Volt::route('stock-transfers/create', 'stock-transfers.create')->name('stock-transfers.create');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager')->group(function () {
        Volt::route('branches', 'branches.index')->name('branches.index');
        Volt::route('branches/create', 'branches.create')->name('branches.create');
        Volt::route('branches/{branch}/edit', 'branches.edit')->name('branches.edit');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper,Cashier,Accountant')->group(function () {
        Volt::route('categories', 'categories.index')->name('categories.index');
        Volt::route('units', 'units.index')->name('units.index');
        Volt::route('products', 'products.index')->name('products.index');
        Volt::route('suppliers', 'suppliers.index')->name('suppliers.index');
        Volt::route('customers', 'customers.index')->name('customers.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper,Accountant')->group(function () {
        Volt::route('purchases', 'purchases.index')->name('purchases.index');
        Volt::route('purchases/{purchase}', 'purchases.show')->name('purchases.show');
        Volt::route('stock-movements', 'stock-movements.index')->name('stock-movements.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Cashier')->group(function () {
        Volt::route('pos', 'pos.index')->name('pos.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Accountant')->group(function () {
        Volt::route('expenses', 'expenses.index')->name('expenses.index');
        Volt::route('expense-categories', 'expense-categories.index')->name('expense-categories.index');
        Volt::route('supplier-balances', 'supplier-balances.index')->name('supplier-balances.index');
        Volt::route('supplier-balances/{supplier}', 'supplier-balances.show')->name('supplier-balances.show');
        Volt::route('supplier-payments/create', 'supplier-payments.create')->name('supplier-payments.create');
        Volt::route('reports/sales', 'reports.sales')->name('reports.sales');
        Volt::route('reports/purchases', 'reports.purchases')->name('reports.purchases');
        Volt::route('reports/expenses', 'reports.expenses')->name('reports.expenses');
        Volt::route('reports/customers', 'reports.customers')->name('reports.customers');
        Volt::route('reports/suppliers', 'reports.suppliers')->name('reports.suppliers');
        Volt::route('reports/stock-valuation', 'reports.stock-valuation')->name('reports.stock-valuation');
        Volt::route('reports/profit-loss', 'reports.profit-loss')->name('reports.profit-loss');
        Volt::route('reports/cashbook', 'reports.cashbook')->name('reports.cashbook');
        Route::get('reports/{report}/export/{format}', ReportExportController::class)->name('reports.export');
        Volt::route('email-settings', 'email-settings.index')->name('email-settings.index');
        Volt::route('purchase-email-logs', 'purchase-email-logs.index')->name('purchase-email-logs.index');
        Volt::route('customer-accounts', 'admin.customer-accounts.index')->name('customer-accounts.index');
        Volt::route('admin/customer-accounts', 'admin.customer-accounts.index')->name('admin.customer-accounts.index');
        Volt::route('admin/customer-portal-users', 'admin.customer-accounts.index')->name('admin.customer-portal-users.index');
        Volt::route('admin/customer-accounts/{customerAccount}', 'admin.customer-accounts.show')->name('admin.customer-accounts.show');
        Volt::route('admin/customer-receipts', 'admin.customer-receipts.index')->name('admin.customer-receipts.index');
        Volt::route('admin/customer-receipts/{customerReceipt}', 'admin.customer-receipts.show')->name('admin.customer-receipts.show');
        Route::get('admin/customer-receipts/{receipt}/download', [CustomerFileDownloadController::class, 'receipt'])->name('admin.customer-receipts.download');
        Volt::route('admin/customer-deposits', 'admin.customer-deposits.index')->name('admin.customer-deposits.index');
        Volt::route('admin/customer-deposits/{customerDeposit}', 'admin.customer-deposits.show')->name('admin.customer-deposits.show');
        Route::get('admin/customer-deposits/{deposit}/download', [CustomerFileDownloadController::class, 'deposit'])->name('admin.customer-deposits.download');
        Volt::route('admin/customer-statements/{customer}', 'admin.customer-statements.show')->name('admin.customer-statements.show');
        Volt::route('admin/customer-notifications', 'admin.customer-notifications.index')->name('admin.customer-notifications.index');
        Volt::route('admin/announcements', 'admin.announcements.index')->name('admin.announcements.index');
        Volt::route('admin/customer-messages', 'admin.customer-messages.index')->name('admin.customer-messages.index');
        Volt::route('admin/message-templates', 'admin.message-templates.index')->name('admin.message-templates.index');
        Volt::route('admin/sent-messages', 'admin.sent-messages.index')->name('admin.sent-messages.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Accountant,Cashier')->group(function () {
        Volt::route('customer-balances', 'customer-balances.index')->name('customer-balances.index');
        Volt::route('customer-balances/{customer}', 'customer-balances.show')->name('customer-balances.show');
        Volt::route('customer-payments/create', 'customer-payments.create')->name('customer-payments.create');
        Volt::route('cashbook', 'cashbook.index')->name('cashbook.index');
        Volt::route('cashbook/{cashbookSession}', 'cashbook.show')->name('cashbook.show');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Cashier,Store Keeper,Accountant')->group(function () {
        Volt::route('sales', 'sales.index')->name('sales.index');
        Volt::route('sales/{sale}', 'sales.show')->name('sales.show');
        Volt::route('sales/{sale}/receipt', 'sales.receipt')->name('sales.receipt');
        Volt::route('sales/{sale}/payments', 'sales.payments')->name('sales.payments');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper,Cashier')->group(function () {
        Volt::route('store-stock', 'store-stock.index')->name('store-stock.index');
        Volt::route('dispensing-stock', 'dispensing-stock.index')->name('dispensing-stock.index');
        Volt::route('inventory-summary', 'inventory-summary.index')->name('inventory-summary.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper')->group(function () {
        Volt::route('stock-adjustments', 'stock-adjustments.index')->name('stock-adjustments.index');
    });

    Route::middleware('role.any:Super Admin,Admin,Manager,Store Keeper,Accountant')->group(function () {
        Volt::route('stock-transfers', 'stock-transfers.index')->name('stock-transfers.index');
        Volt::route('stock-transfers/{stockTransfer}', 'stock-transfers.show')->name('stock-transfers.show');
    });
});

require __DIR__.'/auth.php';
