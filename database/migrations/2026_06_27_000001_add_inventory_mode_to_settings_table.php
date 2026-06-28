<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('enable_warehouse')->default(true)->after('tax_enabled');
            $table->boolean('allow_direct_stock_in')->default(true)->after('enable_warehouse');
            $table->boolean('allow_sales_from_store')->default(false)->after('allow_direct_stock_in');
            $table->foreignId('default_stock_location_id')->nullable()->after('default_branch_id')->constrained('stock_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_stock_location_id');
            $table->dropColumn(['enable_warehouse', 'allow_direct_stock_in', 'allow_sales_from_store']);
        });
    }
};
