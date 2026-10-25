<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->dropUnique('roles_name_guard_name_unique');
            $table->unique(['company_id', 'name', 'guard_name']);
            $table->unique(['company_id', 'source_role_id']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('roles')->select('name', 'guard_name')->groupBy('name', 'guard_name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Company roles must be reconciled before rollback; no roles or assignments have been removed.');
        }
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name', 'guard_name']);
            $table->dropUnique(['company_id', 'source_role_id']);
            $table->dropConstrainedForeignId('source_role_id');
            $table->dropConstrainedForeignId('company_id');
            $table->unique(['name', 'guard_name']);
        });
    }
};
