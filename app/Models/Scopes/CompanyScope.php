<?php

namespace App\Models\Scopes;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Schema::hasColumn($model->getTable(), 'company_id')) {
            return;
        }

        $webUser = BranchAccess::staff();

        if ($webUser instanceof User) {
            if ($webUser->is_system_owner) {
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

        $customerUser = Auth::guard('customer')->hasUser() ? Auth::guard('customer')->user() : null;
        if (! $customerUser && Auth::getDefaultDriver() !== 'web') {
            $guard = Auth::guard();
            $customerUser = $guard->hasUser() ? $guard->user() : null;
        }

        if ($customerUser instanceof CustomerAccount && $customerUser->company_id) {
            $builder->where(
                $model->qualifyColumn('company_id'),
                $customerUser->company_id
            );
        }
    }
}
