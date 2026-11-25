<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\BranchScope;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasCompany
{
    protected static function bootHasCompany(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::addGlobalScope(new BranchScope);
        static::updating(fn ($model) => BranchAccess::validateWrite($model));
        static::deleting(fn ($model) => BranchAccess::validateWrite($model));

        static::creating(function ($model): void {
            $webUser = BranchAccess::staff();
            $customerUser = $webUser?->company_id ? null : CompanyScope::resolvedCustomer();

            if ((! $webUser?->company_id && ! $customerUser?->company_id)
                || ! CompanyScope::hasCompanyColumn($model)) {
                return;
            }

            if ($webUser instanceof User && $webUser->company_id) {
                if (! $webUser->is_system_owner || blank($model->company_id)) {
                    $model->company_id = $webUser->company_id;
                }

                return;
            }

            if ($customerUser?->company_id) {
                $model->company_id = $customerUser->company_id;
            }
        });
        static::creating(fn ($model) => BranchAccess::validateWrite($model));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
