<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_sizes')) {
            return;
        }

        DB::table('product_sizes')
            ->where('symbol', '2½ × 2½')
            ->update([
                'name' => '1½ × 2½',
                'symbol' => '1½ × 2½',
                'description' => 'One and half inch by two and half inch',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_sizes')) {
            return;
        }

        DB::table('product_sizes')
            ->where('symbol', '1½ × 2½')
            ->update([
                'name' => '2½ × 2½',
                'symbol' => '2½ × 2½',
                'description' => 'Two and half inch by two and half inch',
                'updated_at' => now(),
            ]);
    }
};
