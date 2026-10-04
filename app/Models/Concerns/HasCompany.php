<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\CustomerAccount;
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
            if (! Schema::hasColumn($model->getTable(), 'company_id')) {
                return;
            }

            $webUser = Auth::guard('web')->user();

            if ($webUser instanceof User && $webUser->company_id) {
                if (! $webUser->is_system_owner || blank($model->company_id)) {
                    $model->company_id = $webUser->company_id;
                }

                return;
            }

            $customerUser = Auth::guard('customer')->user();

            if ($customerUser instanceof CustomerAccount && $customerUser->company_id) {
                $model->company_id = $customerUser->company_id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
