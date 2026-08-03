<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class CompanyFeatures
{
    public static function currentCompany(): ?Company
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->company_id) {
            return null;
        }

        return Company::query()->find($user->company_id);
    }

    public static function companyId(): ?int
    {
        return self::currentCompany()?->id;
    }

    public static function manufacturingEnabled(?Company $company = null): bool
    {
        if ($company) {
            return $company->manufacturingEnabled();
        }

        return self::currentCompany()?->manufacturingEnabled() ?? false;
    }

    public static function localDate(): string
    {
        $timezone = self::currentCompany()?->timezone ?: config('app.timezone');

        return now($timezone)->toDateString();
    }
}
