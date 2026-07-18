<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('sale_items', 'discount_per_unit')) {
                $table->decimal('discount_per_unit', 15, 2)->default(0)->after('unit_price');
            }

            if (! Schema::hasColumn('sale_items', 'discount_total')) {
                $table->decimal('discount_total', 15, 2)->default(0)->after('discount_amount');
            }

            if (! Schema::hasColumn('sale_items', 'gross_total')) {
                $table->decimal('gross_total', 15, 2)->default(0)->after('discount_total');
            }

            if (! Schema::hasColumn('sale_items', 'net_unit_price')) {
                $table->decimal('net_unit_price', 15, 2)->default(0)->after('gross_total');
            }

            if (! Schema::hasColumn('sale_items', 'net_total')) {
                $table->decimal('net_total', 15, 2)->default(0)->after('net_unit_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table): void {
            foreach (['net_total', 'net_unit_price', 'gross_total', 'discount_total', 'discount_per_unit'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
