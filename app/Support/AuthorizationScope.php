<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AuthorizationScope
{
    public const OWN = 'own';

    public const ASSIGNED_LOCATIONS = 'assigned_locations';

    public const BRANCH = 'branch';

    public const COMPANY = 'company';

    /**
     * Apply company isolation first, then the user's configured sales visibility.
     */
    public static function sales(Builder $query, User $user, string $prefix = ''): Builder
    {
        BranchAccess::scopeAccessibleToUser($query, $user, $prefix);
        $scopes = $user->roles->pluck('sales_scope');
        if (BranchAccess::restricted($user) && $scopes->contains(self::OWN)
            && ! $scopes->contains(self::BRANCH) && ! $scopes->contains(self::COMPANY)) {
            $query->where(fn ($owned) => $owned->where($prefix.'sold_by', $user->id)->orWhere($prefix.'created_by', $user->id));
        }

        return $query;
    }

    public static function reports(Builder $query, User $user, string $prefix = ''): Builder
    {
        return BranchAccess::scopeAccessibleToUser($query, $user, $prefix);
    }

    public static function canAccessSale(User $user, Sale $sale): bool
    {
        return self::sales(Sale::withoutGlobalScopes()->whereKey($sale->id), $user)->exists();
    }

    public static function authorizeSale(User $user, Sale $sale): void
    {
        abort_unless($user->can('sales.view') && self::canAccessSale($user, $sale), 403);
    }

    /** @return Collection<int, int> */
    public static function stockLocationIds(User $user, string $ability = 'can_view'): Collection
    {
        $query = StockLocation::query()
            ->where('company_id', $user->company_id)
            ->when(BranchAccess::restricted($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->where('status', 'active')
            ->where('is_active', true);

        return match (self::scopeFor($user, 'stock_scope', self::ASSIGNED_LOCATIONS)) {
            self::COMPANY => $query->pluck('id')->map(fn ($id): int => (int) $id),
            self::BRANCH => $query->when($user->branch_id !== null, fn ($query) => $query->where('branch_id', $user->branch_id))->pluck('id')->map(fn ($id): int => (int) $id),
            default => $user->permittedStockLocations($ability, $user->branch_id)->pluck('id')->map(fn ($id): int => (int) $id),
        };
    }

    public static function canAccessStockLocation(User $user, int $locationId, string $ability = 'can_view'): bool
    {
        return self::stockLocationIds($user, $ability)->contains($locationId);
    }

    /**
     * Return active stock locations the user may use for a branch workflow.
     * Assigned staff are limited to locations owned by their branch.
     * Company-wide locations are available only to staff without an assigned branch.
     *
     * @return Collection<int, StockLocation>
     */
    public static function stockLocationsForBranch(User $user, string $ability, int $branchId): Collection
    {
        if (! $user->canAccessBranch($branchId)) {
            return collect();
        }
        $query = StockLocation::query()
            ->where('company_id', $user->company_id)
            ->when(BranchAccess::restricted($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(fn (Builder $locations) => $locations
                ->where('branch_id', $branchId)
                ->orWhereNull('branch_id'));

        return match (self::scopeFor($user, 'stock_scope', self::ASSIGNED_LOCATIONS)) {
            self::COMPANY => $query->orderByDesc('is_default')->orderBy('name')->get(),
            self::BRANCH => $user->canAccessBranch($branchId)
                ? $query->orderByDesc('is_default')->orderBy('name')->get()
                : collect(),
            default => $user->permittedStockLocations($ability, $branchId),
        };
    }

    public static function scopeFor(User $user, string $column, string $default): string
    {
        if ($column === 'stock_scope' && $user->branch_id === null) {
            return self::COMPANY;
        }
        if (in_array($column, ['sales_scope', 'report_scope'], true)) {
            return BranchAccess::restricted($user) ? self::BRANCH : self::COMPANY;
        }
        $priority = match ($column) {
            'stock_scope' => [self::COMPANY, self::BRANCH, self::ASSIGNED_LOCATIONS],
            default => [self::COMPANY, self::BRANCH, self::OWN],
        };

        $assigned = $user->roles->pluck($column)->filter()->all();

        foreach ($priority as $scope) {
            if (in_array($scope, $assigned, true)) {
                return $scope;
            }
        }

        return $default;
    }
}
