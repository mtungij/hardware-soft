<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;

class InventorySettings
{
    public static function current(): Setting
    {
        return Setting::query()->first() ?: Setting::query()->create(['company_name' => config('app.name', 'Hardex POS')]);
    }

    public static function warehouseEnabled(): bool
    {
        return (bool) self::current()->enable_warehouse;
    }

    public static function directStockInAllowed(): bool
    {
        $setting = self::current();

        return ! (bool) $setting->enable_warehouse && (bool) $setting->allow_direct_stock_in;
    }

    public static function salesFromStoreAllowed(): bool
    {
        return self::warehouseEnabled() && (bool) self::current()->allow_sales_from_store;
    }

    public static function canChangeMode(): bool
    {
        return ! StockMovement::query()->exists();
    }

    public static function defaultLocation(int $branchId): StockLocation
    {
        $setting = self::current();

        if ($setting->default_stock_location_id) {
            $location = StockLocation::query()
                ->whereKey($setting->default_stock_location_id)
                ->where('branch_id', $branchId)
                ->where('status', 'active')
                ->first();

            if ($location) {
                return $location;
            }
        }

        $inventory = app(InventoryService::class);

        return self::warehouseEnabled()
            ? $inventory->getMainStoreLocation($branchId)
            : $inventory->getDispensingLocation($branchId);
    }

    public static function receivingLocation(int $branchId): StockLocation
    {
        return self::warehouseEnabled()
            ? app(InventoryService::class)->getMainStoreLocation($branchId)
            : app(InventoryService::class)->getDispensingLocation($branchId);
    }

    public static function saleLocations(int $branchId): array
    {
        $inventory = app(InventoryService::class);
        $locations = [$inventory->getDispensingLocation($branchId)->id];

        if (self::salesFromStoreAllowed()) {
            $locations[] = $inventory->getMainStoreLocation($branchId)->id;
        }

        return $locations;
    }

    /**
     * @return array<int, StockLocation>
     */
    public static function allowedSaleLocationsForUser(?User $user, int $branchId): array
    {
        if ($user) {
            $locations = $user->permittedStockLocations('can_sell', $branchId)
                ->filter(fn (StockLocation $location) => $location->can_sell && $location->isActive())
                ->values();

            if ($locations->isNotEmpty()) {
                return $locations->all();
            }
        }

        $inventory = app(InventoryService::class);
        $locations = [];
        $types = self::warehouseEnabled()
            ? ($user?->allowedSalesLocationTypes() ?: ['dispensing'])
            : ['dispensing'];

        if (in_array('store', $types, true)) {
            $locations[] = $inventory->getMainStoreLocation($branchId);
        }

        if (in_array('dispensing', $types, true)) {
            $locations[] = $inventory->getDispensingLocation($branchId);
        }

        return $locations;
    }

    public static function canUserSellFromLocation(?User $user, StockLocation $location): bool
    {
        if (! $location->isActive() || ! $location->can_sell) {
            return false;
        }

        if ($user) {
            $hasPivotAccess = $user->stockLocations()
                ->where('stock_locations.id', $location->id)
                ->wherePivot('can_sell', true)
                ->exists();

            if ($hasPivotAccess) {
                return true;
            }

            // Temporary legacy fallback: if explicit assignments exist, they are authoritative.
            if ($user->stockLocations()->exists()) {
                return false;
            }
        }

        return in_array($location->type, $user?->allowedSalesLocationTypes() ?: ['dispensing'], true);
    }

    public static function stockLocationLabel(StockLocation $location): string
    {
        return $location->name ?: (StockLocation::TYPES[$location->type] ?? str($location->type)->headline()->toString());
    }

    public static function branchId(): int
    {
        return (int) (auth()->user()?->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    }
}
