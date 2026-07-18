<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                if (! Schema::hasColumn('products', 'selling_unit_id')) {
                    $table->foreignId('selling_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
                }

                if (! Schema::hasColumn('products', 'conversion_factor')) {
                    $table->decimal('conversion_factor', 18, 4)->default(1)->after('selling_unit_id');
                }

                if (! Schema::hasColumn('products', 'allow_fractional_sale')) {
                    $table->boolean('allow_fractional_sale')->default(false)->after('conversion_factor');
                }

                if (! Schema::hasColumn('products', 'minimum_sale_quantity')) {
                    $table->decimal('minimum_sale_quantity', 18, 4)->default(1)->after('allow_fractional_sale');
                }

                if (! Schema::hasColumn('products', 'quantity_step')) {
                    $table->decimal('quantity_step', 18, 4)->default(1)->after('minimum_sale_quantity');
                }
            });

            DB::table('products')
                ->whereNull('selling_unit_id')
                ->update(['selling_unit_id' => DB::raw('unit_id')]);
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_items', 'selling_unit_id')) {
                    $table->foreignId('selling_unit_id')->nullable()->after('stock_location_id')->constrained('units')->nullOnDelete();
                }

                if (! Schema::hasColumn('sale_items', 'base_unit_id')) {
                    $table->foreignId('base_unit_id')->nullable()->after('selling_unit_id')->constrained('units')->nullOnDelete();
                }

                if (! Schema::hasColumn('sale_items', 'conversion_factor')) {
                    $table->decimal('conversion_factor', 18, 4)->default(1)->after('base_unit_id');
                }

                if (! Schema::hasColumn('sale_items', 'base_quantity')) {
                    $table->decimal('base_quantity', 18, 4)->default(0)->after('quantity');
                }
            });
        }

        $this->widenDecimalPrecision();
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                foreach (['base_quantity', 'conversion_factor', 'base_unit_id', 'selling_unit_id'] as $column) {
                    if (Schema::hasColumn('sale_items', $column)) {
                        str_ends_with($column, '_id')
                            ? $table->dropConstrainedForeignId($column)
                            : $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                foreach (['quantity_step', 'minimum_sale_quantity', 'allow_fractional_sale', 'conversion_factor', 'selling_unit_id'] as $column) {
                    if (Schema::hasColumn('products', $column)) {
                        $column === 'selling_unit_id'
                            ? $table->dropConstrainedForeignId($column)
                            : $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function widenDecimalPrecision(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $columns = [
            'sale_items' => ['quantity', 'base_quantity'],
            'stock_movements' => ['quantity', 'quantity_in', 'quantity_out'],
            'purchase_items' => ['ordered_quantity', 'received_quantity'],
            'stock_transfer_items' => ['quantity'],
            'stock_adjustments' => ['quantity'],
        ];

        foreach ($columns as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($names as $name) {
                if (Schema::hasColumn($table, $name)) {
                    DB::statement("ALTER TABLE {$table} MODIFY {$name} DECIMAL(18,4) NOT NULL DEFAULT 0");
                }
            }
        }
    }
};
