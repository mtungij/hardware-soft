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
            ['2 ³ᵐᵐ', 'Two inch, 3mm thickness'],
            ['2 ⁴ᵐᵐ', 'Two inch, 4mm thickness'],
            ['2 ⁶ᵐᵐ', 'Two inch, 6mm thickness'],
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

        DB::table('product_sizes')->whereIn('symbol', ['2 ³ᵐᵐ', '2 ⁴ᵐᵐ', '2 ⁶ᵐᵐ'])->delete();
    }
};
