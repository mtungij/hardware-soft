<?php

namespace App\Providers;

use App\Http\Middleware\SetCustomerPortalLocale;
use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\CustomerPayment;
use App\Models\GoodsReceivingNote;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringRelease;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Sale;
use App\Observers\CustomerPaymentWhatsAppObserver;
use App\Observers\OperationalWhatsAppObserver;
use App\Observers\ProductionOrderWhatsAppObserver;
use App\Observers\PurchaseGoodsWhatsAppObserver;
use App\Observers\SaleWhatsAppObserver;
use App\Support\LegacyPermissionAliases;
use App\Support\LocalizedValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->useLangPath(resource_path('lang'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Validator::resolver(
            fn ($translator, $data, $rules, $messages, $attributes) => new LocalizedValidator($translator, $data, $rules, $messages, $attributes)
        );
        Livewire::addPersistentMiddleware([
            SetCustomerPortalLocale::class,
        ]);
        Sale::observe(SaleWhatsAppObserver::class);
        CustomerPayment::observe(CustomerPaymentWhatsAppObserver::class);
        ProductionOrder::observe(ProductionOrderWhatsAppObserver::class);
        Purchase::observe(PurchaseGoodsWhatsAppObserver::class);
        GoodsReceivingNote::observe(PurchaseGoodsWhatsAppObserver::class);
        GoodsReceivingNote::observe(OperationalWhatsAppObserver::class);
        CustomerMaterialCashTransaction::observe(OperationalWhatsAppObserver::class);
        CustomerMaterialIssue::observe(OperationalWhatsAppObserver::class);
        ProductionCuringRelease::observe(OperationalWhatsAppObserver::class);
        ProductionCuringAction::observe(OperationalWhatsAppObserver::class);

        Gate::before(function ($user, string $ability) {
            $legacyAliases = LegacyPermissionAliases::MAP;

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
