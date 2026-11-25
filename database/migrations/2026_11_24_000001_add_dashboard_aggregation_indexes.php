<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'stock_movements' => [
            'stock_movements_dashboard_cover',
            ['company_id', 'stock_location_id', 'product_id', 'branch_id', 'movement_date', 'movement_type', 'quantity_in', 'quantity_out', 'quantity', 'unit_cost'],
        ],
        'sales' => [
            'sales_dashboard_period',
            ['company_id', 'status', 'sale_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            if (Schema::hasIndex($table, $name)) {
                continue;
            }

            if (DB::getDriverName() === 'mysql') {
                $columns = implode(', ', $columns);
                DB::statement("ALTER TABLE {$table} ADD INDEX {$name} ({$columns}), ALGORITHM=INPLACE, LOCK=NONE");

                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
