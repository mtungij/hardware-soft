<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('discount_mode', 16)->nullable()->after('sale_type');
            $table->string('order_discount_type', 16)->nullable()->after('discount_mode');
            $table->decimal('order_discount_value', 15, 2)->nullable()->after('order_discount_type');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('item_discount_type', 16)->nullable()->after('unit_price');
            $table->decimal('item_discount_value', 15, 2)->nullable()->after('item_discount_type');
            $table->decimal('allocated_order_discount', 15, 2)->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['item_discount_type', 'item_discount_value', 'allocated_order_discount']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['discount_mode', 'order_discount_type', 'order_discount_value']);
        });
    }
};
