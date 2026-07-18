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

        $sizes = [
            ['Stick kubwa', 'Large stick'],
            ['Stick ndogo', 'Small stick'],
        ];

        foreach (DB::table('companies')->where('business_type', 'Hardware Store')->pluck('id') as $companyId) {
            foreach ($sizes as [$symbol, $description]) {
                DB::table('product_sizes')->updateOrInsert(
                    ['company_id' => $companyId, 'symbol' => $symbol],
                    [
                        'name' => $symbol,
                        'description' => $description,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_sizes')) {
            return;
        }

        DB::table('product_sizes')->whereIn('symbol', ['Stick kubwa', 'Stick ndogo'])->delete();
    }
};
