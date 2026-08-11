<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->foreignId('curing_stock_location_id')->nullable()->after('raw_material_stock_location_id')
                ->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('finished_goods_release_location_id')->nullable()->after('curing_stock_location_id')
                ->constrained('stock_locations')->restrictOnDelete();
        });

        DB::table('production_orders')->update([
            'curing_stock_location_id' => DB::raw('production_output_stock_location_id'),
            'finished_goods_release_location_id' => DB::raw('COALESCE(final_finished_goods_stock_location_id, finished_goods_stock_location_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finished_goods_release_location_id');
            $table->dropConstrainedForeignId('curing_stock_location_id');
        });
    }
};
