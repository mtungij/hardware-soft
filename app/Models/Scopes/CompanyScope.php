<?php

namespace App\Models\Scopes;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Both session providers query models with this scope while hydrating their identity.
        // Read only resolved principals here. Calling user() on a cold guard re-enters apply().
        $webUser = BranchAccess::staff();

        if ($webUser instanceof User) {
            if ($webUser->is_system_owner || ! self::hasCompanyColumn($model)) {
                return;
            }
            if (! $webUser->company_id) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where(
                $model->qualifyColumn('company_id'),
                $webUser->company_id
            );

            return;
        }

        $customerUser = self::resolvedCustomer();
        if ($customerUser?->company_id && self::hasCompanyColumn($model)) {
            $builder->where($model->qualifyColumn('company_id'), $customerUser->company_id);
        }
    }

    public static function hasCompanyColumn(Model $model): bool
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $request = app()->bound('request') ? request() : null;

        // Keep schema introspection local to a routed HTTP request. Commands and migrations
        // can change the schema while running and must inspect the connection directly.
        if (! $request?->route()) {
            return $connection->getSchemaBuilder()->hasColumn($table, 'company_id');
        }

        $key = 'hardex.company-column:'.json_encode([$connection->getName(), $connection->getDatabaseName(), $connection->getTablePrefix(), $table]);
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $connection->getSchemaBuilder()->hasColumn($table, 'company_id'));
        }

        return $request->attributes->get($key);
    }

    public static function resolvedCustomer(): ?CustomerAccount
    {
        $guard = Auth::guard('customer');
        $customerUser = $guard->hasUser() ? $guard->user() : null;
        if (! $customerUser && Auth::getDefaultDriver() !== 'web') {
            $guard = Auth::guard();
            $customerUser = $guard->hasUser() ? $guard->user() : null;
        }

        return $customerUser instanceof CustomerAccount ? $customerUser : null;
    }
}
