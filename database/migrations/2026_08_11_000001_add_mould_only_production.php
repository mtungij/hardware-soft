<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_families', function (Blueprint $table): void {
            $table->string('production_method', 30)->default('machine_mould')->after('active');
        });

        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->string('production_method', 30)->default('machine_mould')->after('branch_id');
            $table->dropForeign(['machine_id']);
        });
        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('machine_id')->nullable()->change();
            $table->foreign('machine_id')->references('id')->on('machines')->restrictOnDelete();
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('production_method', 30)->default('machine_mould')->after('branch_id');
            $table->foreignId('production_mould_id')->nullable()->after('machine_id')->constrained('production_moulds')->restrictOnDelete();
            $table->dropForeign(['machine_id']);
        });
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('machine_id')->nullable()->change();
            $table->foreign('machine_id')->references('id')->on('machines')->restrictOnDelete();
        });

        DB::table('production_machine_assignments')->update(['production_method' => 'machine_mould']);
        DB::table('production_orders')->update(['production_method' => 'machine_mould']);
        DB::table('production_orders')->whereNotNull('production_machine_assignment_id')->update([
            'production_mould_id' => DB::raw('(select production_mould_id from production_machine_assignments where production_machine_assignments.id = production_orders.production_machine_assignment_id)'),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'production.override_order_locations', 'guard_name' => 'web']);
        Role::query()->whereIn('name', ['Production Manager', 'Admin', 'Super Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('production_mould_id');
            $table->dropColumn('production_method');
        });
        Schema::table('production_machine_assignments', fn (Blueprint $table) => $table->dropColumn('production_method'));
        Schema::table('product_families', fn (Blueprint $table) => $table->dropColumn('production_method'));
        Permission::query()->where('name', 'production.override_order_locations')->where('guard_name', 'web')->delete();
    }
};
