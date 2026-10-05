<?php

namespace App\Models\Scopes;

use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($user = BranchAccess::staff()) {
            BranchAccess::apply($builder, $model, $user);
        }
    }
}
