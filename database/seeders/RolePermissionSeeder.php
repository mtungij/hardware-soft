<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $groups = [
            'dashboard',
            'users',
            'roles',
            'branches',
            'settings',
            'categories',
            'units',
            'products',
            'suppliers',
            'customers',
            'purchases',
            'store stock',
            'stock movements',
            'stock transfers',
            'dispensing stock',
            'inventory summary',
            'sales',
            'expenses',
            'expense categories',
            'customer balances',
            'supplier balances',
            'cashbook',
            'financial reports',
        ];
        $actions = ['view', 'create', 'edit', 'delete', 'export', 'approve'];

        foreach ($groups as $group) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate(['name' => "{$action} {$group}"]);
            }
        }

        foreach (['receive purchases', 'adjust store stock', 'approve stock adjustment', 'complete stock transfers', 'cancel stock transfers', 'access pos', 'sell from store', 'sell from dispensing', 'create credit sales', 'receive sale payments', 'print receipt', 'receive customer payments', 'manage customer portal', 'approve customer accounts', 'approve customer receipts', 'approve customer deposits', 'view customer statements', 'view customer notifications', 'pay suppliers', 'manage cashbook', 'export reports', 'view stock valuation', 'send purchase emails', 'resend purchase emails', 'view email logs', 'manage email settings'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName]);
        }

        $roles = [
            'Super Admin',
            'Admin',
            'Manager',
            'Cashier',
            'Store Keeper',
            'Accountant',
        ];

        foreach ($roles as $roleName) {
            Role::query()->firstOrCreate(['name' => $roleName]);
        }

        Role::findByName('Super Admin')->syncPermissions(Permission::all());
        Role::findByName('Admin')->syncPermissions(Permission::all());
        Role::findByName('Manager')->syncPermissions(Permission::all());
        $inventoryViewPermissions = [
            'view categories',
            'view units',
            'view products',
            'view suppliers',
            'view customers',
        ];

        $storeKeeperPermissions = [
            ...$inventoryViewPermissions,
            'view purchases',
            'create purchases',
            'receive purchases',
            'view store stock',
            'view stock movements',
            'view stock transfers',
            'create stock transfers',
            'complete stock transfers',
            'view dispensing stock',
            'view inventory summary',
            'send purchase emails',
        ];

        Role::findByName('Cashier')->syncPermissions([
            'view dashboard',
            ...$inventoryViewPermissions,
            'view store stock',
            'view dispensing stock',
            'view inventory summary',
            'access pos',
            'view sales',
            'create sales',
            'print receipt',
            'sell from dispensing',
            'receive sale payments',
            'view customer balances',
            'receive customer payments',
            'view cashbook',
        ]);
        Role::findByName('Store Keeper')->syncPermissions(['view dashboard', ...$storeKeeperPermissions, 'view sales', 'sell from store']);
        Role::findByName('Store Keeper')->givePermissionTo('view stock valuation');
        Role::findByName('Accountant')->syncPermissions([
            'view dashboard',
            'export dashboard',
            ...$inventoryViewPermissions,
            'view purchases',
            'view stock movements',
            'view stock transfers',
            'view inventory summary',
            'view sales',
            'receive sale payments',
            'view expenses',
            'create expenses',
            'edit expenses',
            'delete expenses',
            'view expense categories',
            'create expense categories',
            'edit expense categories',
            'delete expense categories',
            'view customer balances',
            'receive customer payments',
            'manage customer portal',
            'approve customer accounts',
            'approve customer receipts',
            'approve customer deposits',
            'view customer statements',
            'view customer notifications',
            'view supplier balances',
            'pay suppliers',
            'view cashbook',
            'manage cashbook',
            'view financial reports',
            'export reports',
            'view stock valuation',
            'send purchase emails',
            'resend purchase emails',
            'view email logs',
            'manage email settings',
        ]);
    }
}
