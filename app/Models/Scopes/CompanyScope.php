<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $guard = Auth::guard('web');

        // Avoid recursive user resolution while the auth guard is hydrating the current user.
        if (! $guard->hasUser()) {
            return;
        }

        $user = $guard->user();

        if (! $user instanceof User || $user->is_system_owner || ! $user->company_id) {
            return;
        }

        if (! Schema::hasColumn($model->getTable(), 'company_id')) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $user->company_id);
    }
}
