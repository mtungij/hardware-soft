<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'production.override_default_locations',
        'production.manage_location_defaults',
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->foreignId('default_raw_material_location_id')->nullable()->after('default_stock_location_id')
                ->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('default_curing_location_id')->nullable()->after('default_raw_material_location_id')
                ->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('default_finished_goods_location_id')->nullable()->after('default_curing_location_id')
                ->constrained('stock_locations')->nullOnDelete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        Role::query()->firstOrCreate(['name' => 'Production Operator', 'guard_name' => 'web']);
        $productionManager = Role::query()->firstOrCreate(['name' => 'Production Manager', 'guard_name' => 'web']);
        $productionManager->givePermissionTo($permissions['production.override_default_locations']);

        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions->values()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_finished_goods_location_id');
            $table->dropConstrainedForeignId('default_curing_location_id');
            $table->dropConstrainedForeignId('default_raw_material_location_id');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
