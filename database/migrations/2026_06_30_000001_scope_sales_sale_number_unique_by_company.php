<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'company_id')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sales_sale_number_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sales_company_sale_number_unique ON sales (company_id, sale_number)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sales_company_sale_number_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sales_sale_number_unique ON sales (sale_number)');
    }
};
