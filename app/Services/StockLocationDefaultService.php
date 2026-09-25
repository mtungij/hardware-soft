<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\StockLocation;
use Illuminate\Support\Facades\DB;

/** The location default is branch-wide, independent of user selling preferences. */
class StockLocationDefaultService
{
    public function save(array $attributes, ?StockLocation $location = null): StockLocation
    {
        return DB::transaction(function () use ($attributes, $location): StockLocation {
            $companyId = (int) ($location?->company_id ?? $attributes['company_id']);
            $branchId = filled($attributes['branch_id'] ?? null) ? (int) $attributes['branch_id'] : null;

            // Lock the parent so concurrent default changes for one branch serialize.
            if ($branchId !== null) {
                Branch::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($branchId)->lockForUpdate()->firstOrFail();
            } else {
                Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            }

            if ($attributes['is_default'] ?? false) {
                StockLocation::query()
                    ->where('company_id', $companyId)
                    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId), fn ($query) => $query->whereNull('branch_id'))
                    ->when($location, fn ($query) => $query->whereKeyNot($location->id))
                    ->update(['is_default' => false]);
            }

            if ($location) {
                $location->update($attributes);

                return $location->refresh();
            }

            return StockLocation::create($attributes);
        });
    }

    public function makeDefault(StockLocation $location): StockLocation
    {
        return $this->save(['branch_id' => $location->branch_id, 'is_default' => true], $location);
    }
}
