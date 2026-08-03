<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('requires_curing')->default(false)->after('inventory_source');
            $table->unsignedSmallInteger('curing_days_required')->nullable()->after('requires_curing');
            $table->unsignedSmallInteger('sellable_after_days')->nullable()->after('curing_days_required');
            $table->text('curing_notes')->nullable()->after('sellable_after_days');
        });

        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->boolean('is_sellable')->default(true)->after('can_sell');
            $table->index(['company_id', 'is_sellable', 'is_active'], 'stk_loc_sellable_ix');
        });

        DB::table('stock_locations')->where('can_sell', false)->update(['is_sellable' => false]);

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->foreignId('production_output_stock_location_id')->nullable()
                ->after('finished_goods_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('final_finished_goods_stock_location_id')->nullable()
                ->after('production_output_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
        });

        DB::table('production_orders')->orderBy('id')->eachById(function (object $order): void {
            DB::table('production_orders')->where('id', $order->id)->update([
                'production_output_stock_location_id' => $order->finished_goods_stock_location_id,
                'final_finished_goods_stock_location_id' => $order->finished_goods_stock_location_id,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('final_finished_goods_stock_location_id');
            $table->dropConstrainedForeignId('production_output_stock_location_id');
        });
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->dropIndex('stk_loc_sellable_ix');
            $table->dropColumn('is_sellable');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['requires_curing', 'curing_days_required', 'sellable_after_days', 'curing_notes']);
        });
    }
};
