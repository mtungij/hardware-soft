<?php

namespace App\Providers;

use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\CustomerPayment;
use App\Models\GoodsReceivingNote;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringRelease;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Observers\CustomerPaymentWhatsAppObserver;
use App\Observers\OperationalWhatsAppObserver;
use App\Observers\ProductionOrderWhatsAppObserver;
use App\Observers\SaleWhatsAppObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sale::observe(SaleWhatsAppObserver::class);
        CustomerPayment::observe(CustomerPaymentWhatsAppObserver::class);
        ProductionOrder::observe(ProductionOrderWhatsAppObserver::class);
        GoodsReceivingNote::observe(OperationalWhatsAppObserver::class);
        CustomerMaterialCashTransaction::observe(OperationalWhatsAppObserver::class);
        CustomerMaterialIssue::observe(OperationalWhatsAppObserver::class);
        ProductionCuringRelease::observe(OperationalWhatsAppObserver::class);
        ProductionCuringAction::observe(OperationalWhatsAppObserver::class);

        Gate::before(function ($user, string $ability) {
            if ($user->hasRole('Super Admin')) {
                return true;
            }

            $legacyAliases = [
                'dashboard.view' => ['view dashboard'],
                'dashboard.sales_summary' => ['view sales'],
                'dashboard.purchase_summary' => ['view purchases'],
                'dashboard.stock_summary' => ['view inventory summary', 'view store stock', 'view dispensing stock'],
                'dashboard.stock_value' => ['view stock valuation'],
                'dashboard.profit' => ['view sales profit'],
                'dashboard.expenses' => ['view expenses'],
                'dashboard.receivables' => ['view customer balances'],
                'sales.create' => ['create sales', 'access pos'],
                'sales.view' => ['view sales'],
                'sales.cancel' => ['edit sales'],
                'sales.view_cost' => ['view sales profit'],
                'sales.view_profit' => ['view sales profit'],
                'sales.export' => ['export reports', 'export pdf', 'export excel'],
                'products.view' => ['view products'],
                'products.create' => ['create products'],
                'products.edit' => ['edit products'],
                'products.delete' => ['delete products'],
                'products.view_buying_price' => ['view sales profit', 'view stock valuation'],
                'products.edit_buying_price' => ['edit products'],
                'products.view_selling_price' => ['view products'],
                'products.edit_selling_price' => ['edit products'],
                'purchases.view' => ['view purchases'],
                'purchases.create' => ['create purchases'],
                'purchases.edit' => ['edit purchases'],
                'purchases.approve' => ['approve purchases'],
                'purchases.receive' => ['receive purchases'],
                'purchases.view_cost' => ['view purchases'],
                'purchases.export' => ['export reports'],
                'stock.view' => ['view inventory summary', 'view store stock', 'view dispensing stock'],
                'stock.adjust' => ['adjust store stock'],
                'stock.transfer' => ['create stock transfers'],
                'stock.receive' => ['receive purchases'],
                'stock.view_value' => ['view stock valuation'],
                'stock.direct_stock_in' => ['create direct stock in'],
                'reports.sales' => ['view financial reports', 'view sales'],
                'reports.stock' => ['view inventory summary'],
                'reports.purchases' => ['view financial reports', 'view purchases'],
                'reports.profit' => ['view sales profit'],
                'reports.expenses' => ['view expenses'],
                'reports.receivables' => ['view customer balances'],
                'reports.export' => ['export reports', 'export pdf', 'export excel'],
                'accounting.view' => ['view financial reports'],
                'accounting.expenses' => ['view expenses'],
                'accounting.profit_loss' => ['view sales profit'],
                'accounting.cashflow' => ['view cashbook'],
                'users.view' => ['view users'],
                'users.create' => ['create users'],
                'users.edit' => ['edit users'],
                'users.delete' => ['delete users'],
                'roles.manage' => ['view roles', 'edit roles'],
                'settings.manage' => ['view settings', 'edit settings'],
            ];

            if (isset($legacyAliases[$ability]) && $user->hasAnyPermission($legacyAliases[$ability])) {
                return true;
            }

            return null;
        });

        RateLimiter::for('customer-api', function (Request $request) {
            return Limit::perMinute(90)->by($request->user()?->id ?: $request->ip());
        });
    }
}
