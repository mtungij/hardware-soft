<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Role;

final class ManufacturingRoleAccess
{
    public const ROLES = ['Production Manager', 'Production Operator', 'Quality Manager', 'Quality Inspector'];

    public static function enabled(int $companyId): bool
    {
        return (bool) Company::query()->whereKey($companyId)->value('manufacturing_enabled');
    }

    public static function permission(string $name): bool
    {
        return str_starts_with($name, 'production.');
    }

    public static function role(Role $role): bool
    {
        if (self::name($role->name) || ($role->source_role_id && self::name((string) Role::query()->whereKey($role->source_role_id)->value('name')))) {
            return true;
        }
        // Custom specialist roles may also include common dashboard/expense access.
        $names = $role->permissions->pluck('name');

        return $names->contains(fn ($name) => self::permission($name))
            && $names->reject(fn ($name) => self::permission($name) || in_array($name, ['view dashboard', 'dashboard.view', 'create expenses'], true))->isEmpty();
    }

    public static function name(string $name): bool
    {
        return in_array($name, self::ROLES, true) || (bool) preg_match('/\b(production|manufacturing|quality)\b/i', $name);
    }
}
