<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'company_id')) {
            return;
        }

        DB::table('stock_movements')
            ->whereNull('company_id')
            ->update([
                'company_id' => DB::raw('(select branches.company_id from branches where branches.id = stock_movements.branch_id)'),
            ]);
    }

    public function down(): void
    {
        //
    }
};
