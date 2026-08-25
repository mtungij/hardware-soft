<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('sales_scope', 32)->default('branch')->after('guard_name');
            $table->string('stock_scope', 32)->default('assigned_locations')->after('sales_scope');
            $table->string('report_scope', 32)->default('branch')->after('stock_scope');
        });

        $now = now();
        $permissions = collect([
            'dashboard.view', 'dashboard.sales_summary', 'dashboard.purchase_summary', 'dashboard.stock_summary',
            'dashboard.stock_value', 'dashboard.profit', 'dashboard.expenses', 'dashboard.receivables',
            'sales.create', 'sales.view', 'sales.edit', 'sales.cancel', 'sales.refund', 'sales.discount',
            'sales.view_cost', 'sales.view_profit', 'sales.export',
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'products.view_buying_price', 'products.view_selling_price',
            'products.edit_buying_price', 'products.edit_selling_price',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.approve',
            'purchases.receive', 'purchases.view_cost', 'purchases.export',
            'stock.view', 'stock.adjust', 'stock.transfer', 'stock.receive', 'stock.view_value', 'stock.direct_stock_in',
            'reports.sales', 'reports.stock', 'reports.purchases', 'reports.profit', 'reports.expenses',
            'reports.receivables', 'reports.export',
            'accounting.view', 'accounting.expenses', 'accounting.profit_loss', 'accounting.cashflow',
            'users.view', 'users.create', 'users.edit', 'users.delete', 'roles.manage', 'settings.manage',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('permissions')->insertOrIgnore($permissions);

        DB::table('roles')->where('name', 'Cashier')->update([
            'sales_scope' => 'own',
            'stock_scope' => 'assigned_locations',
            'report_scope' => 'own',
        ]);
        DB::table('roles')->whereIn('name', ['Store Keeper', 'Production Operator', 'Quality Inspector'])->update([
            'sales_scope' => 'own',
            'stock_scope' => 'assigned_locations',
            'report_scope' => 'branch',
        ]);
        DB::table('roles')->whereIn('name', ['Manager', 'Production Manager', 'Quality Manager'])->update([
            'sales_scope' => 'branch',
            'stock_scope' => 'branch',
            'report_scope' => 'branch',
        ]);
        DB::table('roles')->whereIn('name', ['Super Admin', 'Admin', 'Accountant'])->update([
            'sales_scope' => 'company',
            'stock_scope' => 'company',
            'report_scope' => 'company',
        ]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['sales_scope', 'stock_scope', 'report_scope']);
        });

        // Permission rows are intentionally retained because roles or users may already reference them.
    }
};
