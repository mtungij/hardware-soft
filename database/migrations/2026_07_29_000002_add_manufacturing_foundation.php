<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'manufacturing_enabled')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->boolean('manufacturing_enabled')->default(false);
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'inventory_source')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('inventory_source', 20)->default('purchased');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'inventory_source')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('inventory_source');
            });
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'manufacturing_enabled')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('manufacturing_enabled');
            });
        }
    }
};
