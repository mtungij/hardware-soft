<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuthorizationScope;
use App\Support\BranchAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DashboardStockProjection
{
    public function available(): bool
    {
        if (
            ! config('stock_projection.dashboard_reads', false)
            || DB::getDriverName() !== 'mysql'
        ) {
            return false;
        }

        if (
            ! Schema::hasTable('stock_balances')
            || ! Schema::hasTable('stock_projection_states')
        ) {
            return false;
        }

        $triggers = DB::table('information_schema.triggers')
            ->whereRaw('trigger_schema = DATABASE()')
            ->where('event_object_table', 'stock_movements')
            ->where('action_timing', 'AFTER')
            ->where(function ($query): void {
                $query->where(fn ($q) => $q
                    ->where('trigger_name', 'sb_after_insert')
                    ->where('event_manipulation', 'INSERT'))
                    ->orWhere(fn ($q) => $q
                        ->where('trigger_name', 'sb_after_update')
                        ->where('event_manipulation', 'UPDATE'))
                    ->orWhere(fn ($q) => $q
                        ->where('trigger_name', 'sb_after_delete')
                        ->where('event_manipulation', 'DELETE'));
            })
            ->count();

        if ($triggers !== 3) {
            return false;
        }

        return DB::table('stock_projection_states')
            ->where('name', 'stock_balances')
            ->where('is_ready', true)
            ->whereNotNull('verified_at')
            ->exists();
    }

    public function scopedBalances(
        iterable $locationIds,
        ?int $branchId = null
    ): Builder {
        $user = Auth::guard('web')->user();

        abort_unless(
            $user instanceof User && $user->company_id,
            403
        );

        $allowed = AuthorizationScope::stockLocationIds($user)
            ->map(fn ($id): int => (int) $id);

        $requested = collect($locationIds)
            ->map(fn ($id): int => (int) $id);

        $locations = $allowed
            ->intersect($requested)
            ->unique()
            ->values();

        $query = DB::table('stock_balances')
            ->where('company_id', $user->company_id)
            ->whereIn('stock_location_id', $locations);

        if (BranchAccess::restricted($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    public function quantities(
        iterable $locationIds,
        ?int $branchId = null
    ): Builder {
        return $this->scopedBalances($locationIds, $branchId)
            ->select('stock_location_id', 'product_id')
            ->selectRaw('SUM(signed_quantity) AS signed_quantity')
            ->groupBy('stock_location_id', 'product_id');
    }
}
