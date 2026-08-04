<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductionLocationService
{
    public const RAW = 'raw';

    public const CURING = 'curing';

    public const FINISHED = 'finished';

    public const CONFIGURATION_MESSAGE = 'Production location defaults are not fully configured. Ask an administrator to configure the Raw Material Warehouse, Curing Yard, and Finished Goods Warehouse in Company Settings.';

    /** @return array{raw: StockLocation, curing: StockLocation, finished: StockLocation} */
    public function defaults(int $companyId, ?int $branchId): array
    {
        $setting = Setting::withoutGlobalScopes()->where('company_id', $companyId)->first();
        $locations = [
            self::RAW => $setting?->default_raw_material_location_id,
            self::CURING => $setting?->default_curing_location_id,
            self::FINISHED => $setting?->default_finished_goods_location_id,
        ];

        if (collect($locations)->contains(fn ($id) => blank($id))) {
            throw ValidationException::withMessages(['production_location_defaults' => self::CONFIGURATION_MESSAGE]);
        }

        $resolved = [];
        foreach ($locations as $purpose => $locationId) {
            $resolved[$purpose] = $this->location((int) $locationId, $purpose, $companyId, $branchId);
        }

        return $resolved;
    }

    public function location(int $locationId, string $purpose, int $companyId, ?int $branchId, ?User $user = null, ?string $field = null): StockLocation
    {
        $location = $this->eligibleQuery($purpose, $companyId, $branchId)->whereKey($locationId)->first();

        if (! $location || ($user && ! $this->userCanUse($user, $location, $purpose))) {
            throw ValidationException::withMessages([
                ($field ?: $this->field($purpose)) => $this->invalidMessage($purpose),
            ]);
        }

        return $location;
    }

    /** @return Collection<int, StockLocation> */
    public function eligibleLocations(string $purpose, int $companyId, ?int $branchId, ?User $user = null): Collection
    {
        return $this->eligibleQuery($purpose, $companyId, $branchId)
            ->with('branch')->orderBy('branch_id')->orderBy('name')->get()
            ->when($user, fn (Collection $locations) => $locations
                ->filter(fn (StockLocation $location) => $this->userCanUse($user, $location, $purpose))->values());
    }

    private function eligibleQuery(string $purpose, int $companyId, ?int $branchId): Builder
    {
        $query = StockLocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($branchId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('branch_id')->orWhere('branch_id', $branchId)));

        return match ($purpose) {
            self::RAW => $query
                ->whereIn('type', ['warehouse', 'store', 'branch_store'])
                ->where('is_sellable', false)
                ->where('can_receive_stock', true)
                ->where('can_issue_stock', true),
            self::CURING => $query
                ->where('type', 'curing')
                ->where('is_sellable', false)
                ->where('can_receive_stock', true)
                ->where('can_issue_stock', true),
            self::FINISHED => $query
                ->whereIn('type', ['warehouse', 'store', 'branch_store', 'showroom'])
                ->where('is_sellable', true)
                ->where('can_sell', true)
                ->where('can_receive_stock', true)
                ->where('can_issue_stock', true),
            default => throw new \InvalidArgumentException("Unknown production location purpose [{$purpose}]."),
        };
    }

    private function userCanUse(User $user, StockLocation $location, string $purpose): bool
    {
        if (! $user->stockLocations()->exists()) {
            return true;
        }

        $ability = $purpose === self::RAW ? 'can_transfer' : 'can_receive';

        return $user->stockLocations()
            ->where('stock_locations.id', $location->id)
            ->wherePivot($ability, true)
            ->exists();
    }

    private function field(string $purpose): string
    {
        return match ($purpose) {
            self::RAW => 'raw_material_stock_location_id',
            self::CURING => 'production_output_stock_location_id',
            self::FINISHED => 'final_finished_goods_stock_location_id',
        };
    }

    private function invalidMessage(string $purpose): string
    {
        $label = match ($purpose) {
            self::RAW => 'raw-material warehouse',
            self::CURING => 'curing yard',
            self::FINISHED => 'finished-goods warehouse',
        };

        return "Select an active, company- and branch-compatible {$label} with the required inventory capabilities.";
    }
}
