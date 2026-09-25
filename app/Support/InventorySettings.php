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
        if (! self::warehouseEnabled()) {
            return [$inventory->getDispensingLocation($branchId)->id];
        }

        return StockLocation::query()
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->where('status', 'active')
            ->where('is_active', true)
            ->where('can_sell', true)
            ->where('is_sellable', true)
            ->where(fn ($query) => $query
                ->where('type', 'dispensing')
                ->orWhere('is_dispensing_location', true)
                ->when(self::salesFromStoreAllowed(), fn ($query) => $query->orWhere('type', 'store')))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, StockLocation>
     */
    public static function allowedSaleLocationsForUser(?User $user, int $branchId): array
    {
        if ($user) {
            $locations = $user->permittedStockLocations('can_sell', $branchId)
                ->filter(fn (StockLocation $location) => $location->can_sell && $location->is_sellable && $location->isActive())
                ->values();

            $hasExplicitAssignments = $user->stockLocations()
                ->where('stock_locations.branch_id', $branchId)
                ->exists();

            if ($locations->isNotEmpty() || $hasExplicitAssignments) {
                return $locations->all();
            }
        }

        if (! self::warehouseEnabled()) {
            return [app(InventoryService::class)->getDispensingLocation($branchId)];
        }

        $types = $user?->allowedSalesLocationTypes() ?: ['dispensing'];

        return StockLocation::query()
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->where('status', 'active')
            ->where('is_active', true)
            ->where('can_sell', true)
            ->where('is_sellable', true)
            ->where(fn ($query) => $query
                ->whereIn('type', $types)
                ->when(in_array('dispensing', $types, true), fn ($query) => $query->orWhere('is_dispensing_location', true)))
            ->orderByDesc('is_default')->orderBy('name')->get()->all();
    }

    public static function canUserSellFromLocation(?User $user, StockLocation $location): bool
    {
        if (! $location->isActive() || ! $location->can_sell || ! $location->is_sellable) {
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

        return in_array($location->type, $user?->allowedSalesLocationTypes() ?: ['dispensing'], true)
            || ($location->is_dispensing_location && in_array('dispensing', $user?->allowedSalesLocationTypes() ?: ['dispensing'], true));
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
