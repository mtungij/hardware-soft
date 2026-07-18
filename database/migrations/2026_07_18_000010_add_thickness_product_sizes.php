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
            ['¾ ³ᵐᵐ', 'Three quarter inch, 3mm thickness'],
            ['¾ ⁴ᵐᵐ', 'Three quarter inch, 4mm thickness'],
            ['1 ³ᵐᵐ', 'One inch, 3mm thickness'],
            ['1 ⁴ᵐᵐ', 'One inch, 4mm thickness'],
            ['1½ ³ᵐᵐ', 'One and half inch, 3mm thickness'],
            ['1½ ⁴ᵐᵐ', 'One and half inch, 4mm thickness'],
            ['1½ ⁶ᵐᵐ', 'One and half inch, 6mm thickness'],
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

        DB::table('product_sizes')
            ->whereIn('symbol', [
                '¾ ³ᵐᵐ',
                '¾ ⁴ᵐᵐ',
                '1 ³ᵐᵐ',
                '1 ⁴ᵐᵐ',
                '1½ ³ᵐᵐ',
                '1½ ⁴ᵐᵐ',
                '1½ ⁶ᵐᵐ',
            ])
            ->delete();
    }
};
