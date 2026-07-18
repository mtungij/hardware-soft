<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameSizes([
            '¾ (3mm)' => ['¾ ³ᵐᵐ', 'Three quarter inch, 3mm thickness'],
            '¾ (4mm)' => ['¾ ⁴ᵐᵐ', 'Three quarter inch, 4mm thickness'],
            '1 (3mm)' => ['1 ³ᵐᵐ', 'One inch, 3mm thickness'],
            '1 (4mm)' => ['1 ⁴ᵐᵐ', 'One inch, 4mm thickness'],
            '1½ (3mm)' => ['1½ ³ᵐᵐ', 'One and half inch, 3mm thickness'],
            '1½ (4mm)' => ['1½ ⁴ᵐᵐ', 'One and half inch, 4mm thickness'],
            '1½ (6mm)' => ['1½ ⁶ᵐᵐ', 'One and half inch, 6mm thickness'],
        ]);
    }

    public function down(): void
    {
        $this->renameSizes([
            '¾ ³ᵐᵐ' => ['¾ (3mm)', 'Three quarter inch, 3mm thickness'],
            '¾ ⁴ᵐᵐ' => ['¾ (4mm)', 'Three quarter inch, 4mm thickness'],
            '1 ³ᵐᵐ' => ['1 (3mm)', 'One inch, 3mm thickness'],
            '1 ⁴ᵐᵐ' => ['1 (4mm)', 'One inch, 4mm thickness'],
            '1½ ³ᵐᵐ' => ['1½ (3mm)', 'One and half inch, 3mm thickness'],
            '1½ ⁴ᵐᵐ' => ['1½ (4mm)', 'One and half inch, 4mm thickness'],
            '1½ ⁶ᵐᵐ' => ['1½ (6mm)', 'One and half inch, 6mm thickness'],
        ]);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $sizes
     */
    private function renameSizes(array $sizes): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('product_sizes')) {
            return;
        }

        $hasProducts = Schema::hasTable('products') && Schema::hasColumn('products', 'product_size_id');

        foreach (DB::table('companies')->where('business_type', 'Hardware Store')->pluck('id') as $companyId) {
            foreach ($sizes as $oldSymbol => [$newSymbol, $description]) {
                $oldSize = DB::table('product_sizes')
                    ->where('company_id', $companyId)
                    ->where('symbol', $oldSymbol)
                    ->first();

                $newSize = DB::table('product_sizes')
                    ->where('company_id', $companyId)
                    ->where('symbol', $newSymbol)
                    ->first();

                if ($oldSize && $newSize && $oldSize->id !== $newSize->id) {
                    if ($hasProducts) {
                        DB::table('products')
                            ->where('product_size_id', $oldSize->id)
                            ->update(['product_size_id' => $newSize->id]);
                    }

                    DB::table('product_sizes')->where('id', $oldSize->id)->delete();
                    $oldSize = null;
                }

                if ($oldSize) {
                    DB::table('product_sizes')
                        ->where('id', $oldSize->id)
                        ->update([
                            'name' => $newSymbol,
                            'symbol' => $newSymbol,
                            'description' => $description,
                            'status' => 'active',
                            'updated_at' => now(),
                        ]);

                    continue;
                }

                DB::table('product_sizes')->updateOrInsert(
                    ['company_id' => $companyId, 'symbol' => $newSymbol],
                    [
                        'name' => $newSymbol,
                        'description' => $description,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
