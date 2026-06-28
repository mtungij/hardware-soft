<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
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
        return (bool) self::current()->allow_direct_stock_in;
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

    public static function branchId(): int
    {
        return (int) (auth()->user()?->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    }
}
