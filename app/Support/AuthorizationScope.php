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
        $companyColumn = $prefix.'company_id';
        $branchColumn = $prefix.'branch_id';
        $soldByColumn = $prefix.'sold_by';
        $createdByColumn = $prefix.'created_by';

        $query->where($companyColumn, $user->company_id);

        return match (self::scopeFor($user, 'sales_scope', self::BRANCH)) {
            self::COMPANY => $query,
            self::OWN => $query->where(fn (Builder $owned) => $owned
                ->where($soldByColumn, $user->id)
                ->orWhere($createdByColumn, $user->id)),
            default => $query->where($branchColumn, $user->branch_id),
        };
    }

    public static function reports(Builder $query, User $user, string $prefix = ''): Builder
    {
        $query->where($prefix.'company_id', $user->company_id);

        return match (self::scopeFor($user, 'report_scope', self::BRANCH)) {
            self::COMPANY => $query,
            self::OWN => $query->where(fn (Builder $owned) => $owned
                ->where($prefix.'sold_by', $user->id)
                ->orWhere($prefix.'created_by', $user->id)),
            default => $query->where($prefix.'branch_id', $user->branch_id),
        };
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
        $query = StockLocation::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('status', 'active')
            ->where('is_active', true);

        return match (self::scopeFor($user, 'stock_scope', self::ASSIGNED_LOCATIONS)) {
            self::COMPANY => $query->pluck('id')->map(fn ($id): int => (int) $id),
            self::BRANCH => $query->where('branch_id', $user->branch_id)->pluck('id')->map(fn ($id): int => (int) $id),
            default => $user->permittedStockLocations($ability, $user->branch_id)->pluck('id')->map(fn ($id): int => (int) $id),
        };
    }

    public static function canAccessStockLocation(User $user, int $locationId, string $ability = 'can_view'): bool
    {
        return self::stockLocationIds($user, $ability)->contains($locationId);
    }

    public static function scopeFor(User $user, string $column, string $default): string
    {
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
