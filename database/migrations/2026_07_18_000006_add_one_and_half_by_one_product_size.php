<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('product_sizes')) {
            return;
        }

        foreach (DB::table('companies')->where('business_type', 'Hardware Store')->pluck('id') as $companyId) {
            DB::table('product_sizes')->updateOrInsert(
                ['company_id' => $companyId, 'symbol' => '1½ × 1'],
                [
                    'name' => '1½ × 1',
                    'description' => 'One and half inch by one inch',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_sizes')) {
            return;
        }

        DB::table('product_sizes')->where('symbol', '1½ × 1')->delete();
    }
};
