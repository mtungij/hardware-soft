<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('stock_movements', 'stock_movements_dashboard_receipts')) {
            DB::statement('ALTER TABLE stock_movements ADD INDEX stock_movements_dashboard_receipts
                (company_id, movement_date, movement_type, stock_location_id, branch_id, quantity_in, quantity_out, quantity),
                ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('stock_movements', 'stock_movements_dashboard_receipts')) {
            DB::statement('ALTER TABLE stock_movements DROP INDEX stock_movements_dashboard_receipts, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }
};
