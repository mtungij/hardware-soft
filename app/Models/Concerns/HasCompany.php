<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

trait HasCompany
{
    protected static function bootHasCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            $guard = Auth::guard('web');

            if (! $guard->hasUser()) {
                return;
            }

            $user = $guard->user();

            if (! $user instanceof User || ! $user->company_id) {
                return;
            }

            if (! Schema::hasColumn($model->getTable(), 'company_id')) {
                return;
            }

            if (! $user->is_system_owner || blank($model->company_id)) {
                $model->company_id = $user->company_id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
