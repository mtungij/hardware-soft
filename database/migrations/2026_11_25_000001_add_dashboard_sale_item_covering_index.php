<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('sale_items', 'sale_items_dashboard_cover')) {
            DB::statement('ALTER TABLE sale_items ADD INDEX sale_items_dashboard_cover
                (sale_id, company_id, sale_type, product_id, quantity, unit_cost, line_total),
                ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('sale_items', 'sale_items_dashboard_cover')) {
            DB::statement('ALTER TABLE sale_items DROP INDEX sale_items_dashboard_cover, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }
};
