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
            $table->foreignId('purchase_unit_id')
                ->nullable()
                ->after('measurement_type_id')
                ->constrained('units')
                ->restrictOnDelete();
            $table->decimal('purchase_conversion_factor', 15, 4)
                ->default(1)
                ->after('purchase_unit_id');
        });

        DB::table('products')->whereNull('purchase_unit_id')->update([
            'purchase_unit_id' => DB::raw('unit_id'),
            'purchase_conversion_factor' => 1,
        ]);

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->foreignId('purchase_unit_id')->nullable()->after('product_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->after('purchase_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('purchase_conversion_factor', 15, 4)->default(1)->after('stock_unit_id');
        });

        DB::table('purchase_items')
            ->join('products', 'purchase_items.product_id', '=', 'products.id')
            ->select('purchase_items.id', 'products.purchase_unit_id', 'products.unit_id', 'products.purchase_conversion_factor')
            ->orderBy('purchase_items.id')
            ->each(function (object $item): void {
                DB::table('purchase_items')->where('id', $item->id)->update([
                    'purchase_unit_id' => $item->purchase_unit_id ?: $item->unit_id,
                    'stock_unit_id' => $item->unit_id,
                    'purchase_conversion_factor' => $item->purchase_conversion_factor ?: 1,
                ]);
            });

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->foreignId('purchase_unit_id')->nullable()->after('product_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->after('purchase_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('stock_quantity', 15, 4)->nullable()->after('received_quantity');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach (['ordered_quantity', 'previously_received_quantity', 'received_quantity'] as $column) {
                if (Schema::hasColumn('goods_receiving_note_items', $column)) {
                    DB::statement("ALTER TABLE goods_receiving_note_items MODIFY {$column} DECIMAL(18,4) NOT NULL DEFAULT 0");
                }
            }
        }

        DB::table('goods_receiving_note_items')
            ->join('purchase_items', 'goods_receiving_note_items.purchase_item_id', '=', 'purchase_items.id')
            ->select(
                'goods_receiving_note_items.id',
                'goods_receiving_note_items.received_quantity',
                'purchase_items.purchase_unit_id',
                'purchase_items.stock_unit_id',
                'purchase_items.purchase_conversion_factor',
            )
            ->orderBy('goods_receiving_note_items.id')
            ->each(function (object $item): void {
                DB::table('goods_receiving_note_items')->where('id', $item->id)->update([
                    'purchase_unit_id' => $item->purchase_unit_id,
                    'stock_unit_id' => $item->stock_unit_id,
                    'stock_quantity' => (float) $item->received_quantity * (float) ($item->purchase_conversion_factor ?: 1),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropConstrainedForeignId('stock_unit_id');
            $table->dropColumn('stock_quantity');
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropConstrainedForeignId('stock_unit_id');
            $table->dropColumn('purchase_conversion_factor');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropColumn('purchase_conversion_factor');
        });
    }
};
