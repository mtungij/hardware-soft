<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private string $guard = 'web';

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
            'direct stock in',
            'inventory summary',
            'sales',
            'expenses',
            'expense categories',
            'customer balances',
            'supplier balances',
            'cashbook',
            'financial reports',
            'announcements',
            'customer messages',
            'message templates',
            'sent messages',
        ];
        $actions = ['view', 'create', 'edit', 'delete', 'export', 'approve'];

        foreach ($groups as $group) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate(['name' => "{$action} {$group}", 'guard_name' => $this->guard]);
            }
        }

        foreach (['view user stock locations', 'manage user stock locations', 'assign selling locations', 'assign transfer locations', 'assign receiving locations', 'assign default stock location', 'view all stock locations', 'sell from multiple locations', 'manage cross branch stock locations', 'receive purchases', 'edit draft goods receipts', 'post goods receipts', 'cancel goods receipts', 'adjust purchase receiving cost', 'adjust store stock', 'approve stock adjustment', 'complete stock transfers', 'cancel stock transfers', 'access pos', 'sell from store', 'sell from dispensing', 'create credit sales', 'create unassigned credit sales', 'view unassigned credit sales', 'assign unassigned credit customer', 'receive credit payments', 'view customer balances', 'customer-balances.view', 'receive sale payments', 'print receipt', 'receive customer payments', 'customer-payments.view', 'customer-payments.create', 'customer-payments.print', 'customer-payment-reports.view', 'manage customer portal', 'approve customer accounts', 'approve customer receipts', 'approve customer deposits', 'view customer statements', 'customer-statements.view', 'customer-statements.export', 'view customer notifications', 'manage customer communications', 'publish announcements', 'send customer messages', 'pay suppliers', 'manage cashbook', 'export reports', 'export pdf', 'export excel', 'print reports', 'view stock valuation', 'view sales profit', 'send purchase emails', 'resend purchase emails', 'view email logs', 'manage email settings', 'company-settings.update', 'production.view', 'production.view_product_families', 'production.manage_product_families', 'production.view_moulds', 'production.manage_moulds', 'production.manage_machines', 'production.manage_schedule', 'production.view_recipes', 'production.manage_recipes', 'production.view_orders', 'production.create_orders', 'production.execute_orders', 'production.complete_orders', 'production.cancel_orders', 'production.override_default_locations', 'production.manage_location_defaults'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        foreach (['production.view_curing', 'production.manage_curing', 'production.release_curing'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        foreach (['production.view_costing', 'production.manage_costing', 'production.finalize_costing'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        foreach (['production.view_quality', 'production.manage_quality_plans', 'production.perform_quality_inspections', 'production.approve_quality', 'production.manage_quality_holds', 'production.record_qc_result', 'production.approve_qc', 'production.manage_qc_plans', 'production.override_qc_separation'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        foreach (['production.view_reports', 'production.export_reports', 'production.view_cost_reports', 'production.view_batch_traceability'] as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        $materialAccountPermissions = [
            'customer_material_accounts.view',
            'customer_material_accounts.create',
            'customer_material_accounts.edit',
            'customer_material_accounts.record_deposit',
            'customer_material_accounts.issue_material',
            'customer_material_accounts.refund',
            'customer_material_accounts.cancel',
            'customer_material_accounts.override_price',
            'customer_material_accounts.reports',
        ];
        foreach ($materialAccountPermissions as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $this->guard]);
        }

        $roles = [
            'Super Admin',
            'Admin',
            'Manager',
            'Cashier',
            'Store Keeper',
            'Accountant',
            'Quality Manager',
            'Quality Inspector',
            'Production Manager',
            'Production Operator',
        ];

        foreach ($roles as $roleName) {
            Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => $this->guard]);
        }

        Role::findByName('Super Admin', $this->guard)->syncPermissions(Permission::where('guard_name', $this->guard)->get());
        Role::findByName('Admin', $this->guard)->syncPermissions(Permission::where('guard_name', $this->guard)->get());
        Role::findByName('Manager', $this->guard)->syncPermissions(Permission::where('guard_name', $this->guard)->get());
        Role::findByName('Manager', $this->guard)->revokePermissionTo('company-settings.update');
        Role::findByName('Manager', $this->guard)->revokePermissionTo('production.manage_machines');
        Role::findByName('Manager', $this->guard)->revokePermissionTo('production.finalize_costing');
        Role::findByName('Manager', $this->guard)->revokePermissionTo(['production.manage_quality_plans', 'production.approve_quality']);
        Role::findByName('Manager', $this->guard)->revokePermissionTo('production.view_cost_reports');
        Role::findByName('Manager', $this->guard)->revokePermissionTo(['production.override_default_locations', 'production.manage_location_defaults']);
        Role::findByName('Production Manager', $this->guard)->givePermissionTo([
            'view dashboard', 'production.view', 'production.view_moulds', 'production.manage_moulds',
            'production.manage_machines', 'production.manage_schedule', 'production.view_recipes',
            'production.manage_recipes', 'production.view_orders', 'production.create_orders',
            'production.execute_orders', 'production.complete_orders', 'production.cancel_orders',
            'production.override_default_locations',
        ]);
        Role::findByName('Production Operator', $this->guard)->givePermissionTo([
            'view dashboard', 'production.view', 'production.view_orders',
            'production.create_orders', 'production.execute_orders',
        ]);
        Role::findByName('Quality Manager', $this->guard)->syncPermissions(['view dashboard', 'production.view', 'production.view_quality', 'production.manage_quality_plans', 'production.perform_quality_inspections', 'production.approve_quality', 'production.manage_quality_holds', 'production.record_qc_result', 'production.approve_qc', 'production.manage_qc_plans']);
        Role::findByName('Quality Inspector', $this->guard)->syncPermissions(['view dashboard', 'production.view', 'production.view_quality', 'production.perform_quality_inspections', 'production.record_qc_result']);
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
            'edit draft goods receipts',
            'post goods receipts',
            'view store stock',
            'view stock movements',
            'view stock transfers',
            'create stock transfers',
            'complete stock transfers',
            'view dispensing stock',
            'view inventory summary',
            'view direct stock in',
            'create direct stock in',
            'send purchase emails',
        ];

        Role::findByName('Cashier', $this->guard)->syncPermissions([
            'view dashboard',
            ...$inventoryViewPermissions,
            'view store stock',
            'view dispensing stock',
            'view inventory summary',
            'access pos',
            'view sales',
            'create sales',
            'create credit sales',
            'create unassigned credit sales',
            'print receipt',
            'print reports',
            'sell from dispensing',
            'receive sale payments',
            'receive credit payments',
            'view customer balances',
            'receive customer payments',
            'view cashbook',
            'customer_material_accounts.view',
            'customer_material_accounts.create',
            'customer_material_accounts.record_deposit',
        ]);
        Role::findByName('Store Keeper', $this->guard)->syncPermissions(['view dashboard', ...$storeKeeperPermissions, 'view sales', 'sell from store', 'customer_material_accounts.view', 'customer_material_accounts.issue_material']);
        Role::findByName('Store Keeper', $this->guard)->givePermissionTo('view stock valuation');
        Role::findByName('Store Keeper', $this->guard)->givePermissionTo(['export pdf', 'export excel', 'print reports']);
        Role::findByName('Accountant', $this->guard)->syncPermissions([
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
            'manage customer communications',
            'publish announcements',
            'send customer messages',
            'view announcements',
            'create announcements',
            'edit announcements',
            'delete announcements',
            'view customer messages',
            'create customer messages',
            'edit customer messages',
            'delete customer messages',
            'view message templates',
            'create message templates',
            'edit message templates',
            'delete message templates',
            'view sent messages',
            'view supplier balances',
            'pay suppliers',
            'view cashbook',
            'manage cashbook',
            'view financial reports',
            'production.view_costing',
            'production.finalize_costing',
            'export reports',
            'export pdf',
            'export excel',
            'print reports',
            'view stock valuation',
            'send purchase emails',
            'resend purchase emails',
            'view email logs',
            'manage email settings',
            'customer_material_accounts.view',
            'customer_material_accounts.record_deposit',
            'customer_material_accounts.refund',
            'customer_material_accounts.reports',
        ]);
    }
}
