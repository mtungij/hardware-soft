<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM('purchase_in', 'transfer_in', 'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out', 'return_in', 'direct_stock_in') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM('purchase_in', 'transfer_in', 'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out', 'return_in') NOT NULL");
    }
};
