<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RolePermissionCatalog
{
    public static function groups(): Collection
    {
        // Retain historical permission rows and grants; receipts inherit sale access.
        return Permission::query()->where('guard_name', 'web')->where('name', '!=', 'print receipt')->orderBy('name')->get()
            ->groupBy(fn ($permission) => self::module($permission->name))->sortKeys();
    }

    public static function module(string $name): string
    {
        return match (true) {
            str_contains($name, 'report'), str_contains($name, 'export'), str_contains($name, 'print') => 'Reports',
            str_starts_with($name, 'production.') => 'Production',
            str_contains($name, 'dashboard') => 'Dashboard',
            str_contains($name, 'sale'), str_contains($name, 'pos') => 'Sales / POS',
            str_contains($name, 'purchase'), str_contains($name, 'supplier') => 'Purchases',
            str_contains($name, 'stock'), str_contains($name, 'inventory'), str_contains($name, 'goods receipt') => 'Warehouse / Stock',
            str_contains($name, 'product'), str_contains($name, 'categor'), str_contains($name, 'unit') => 'Products',
            str_contains($name, 'customer') => 'Customers',
            str_contains($name, 'accounting'), str_contains($name, 'expense'), str_contains($name, 'cashbook'), str_contains($name, 'profit') => 'Accounting',
            str_contains($name, 'user'), str_contains($name, 'role') => 'Users',
            default => 'Settings & Other',
        };
    }

    public static function label(string $name): string
    {
        if ($name === 'sales.discount') {
            return __('permissions.apply_discount');
        }
        $key = 'permissions.p_'.substr(sha1($name), 0, 12);

        return Lang::has($key, app()->getLocale(), false)
            ? __($key) : Str::headline(str_replace('.', ' ', $name));
    }
}
