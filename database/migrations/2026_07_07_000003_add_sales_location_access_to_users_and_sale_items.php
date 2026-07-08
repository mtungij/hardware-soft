<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('sales_location_access', ['store', 'dispensing', 'both'])
                ->default('dispensing')
                ->after('status');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('sold_from_label')->nullable()->after('stock_location_id');
        });

        DB::table('users')
            ->whereIn('id', function ($query): void {
                $query->select('model_id')
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('roles.name', ['Super Admin', 'Admin', 'Manager']);
            })
            ->update(['sales_location_access' => 'both']);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('sold_from_label');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sales_location_access');
        });
    }
};
