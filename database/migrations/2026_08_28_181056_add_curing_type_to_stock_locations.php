<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


    /**
     * Run the migrations.
     */
   return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE stock_locations
                MODIFY type ENUM(
                    'warehouse',
                    'store',
                    'dispensing',
                    'showroom',
                    'branch_store',
                    'returns',
                    'damaged',
                    'transit',
                    'curing',
                    'other'
                ) NOT NULL DEFAULT 'store'
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE stock_locations
                MODIFY type ENUM(
                    'warehouse',
                    'store',
                    'dispensing',
                    'showroom',
                    'branch_store',
                    'returns',
                    'damaged',
                    'transit',
                    'other'
                ) NOT NULL DEFAULT 'store'
            ");
        }
    }
};
