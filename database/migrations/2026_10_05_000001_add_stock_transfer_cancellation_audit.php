<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_TYPES = [
        'purchase_in', 'purchase_receipt', 'purchase_receipt_reversal', 'transfer_in',
        'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out',
        'return_in', 'direct_stock_in', 'production_output', 'production_consumption',
        'curing_release_in', 'curing_release_out', 'curing_damage', 'opening_stock',
        'internal_sale_out', 'internal_sale_in',
    ];

    public function up(): void
    {
        Schema::table('stock_transfers', fn (Blueprint $table) => $table->text('cancellation_reason')->nullable());
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('stock_transfer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('original_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
        });
        $this->movementTypes([...self::PREVIOUS_TYPES, 'transfer_cancel_out', 'transfer_cancel_in']);
    }

    public function down(): void
    {
        if (DB::table('stock_transfers')->whereNotNull('cancelled_at')->whereNotNull('cancellation_reason')->exists()
            || DB::table('stock_movements')->whereIn('movement_type', ['transfer_cancel_out', 'transfer_cancel_in'])->exists()) {
            throw new RuntimeException('Cannot remove stock transfer cancellation audit history.');
        }
        $this->movementTypes(self::PREVIOUS_TYPES);
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_movement_id');
            $table->dropConstrainedForeignId('stock_transfer_item_id');
            $table->dropConstrainedForeignId('stock_transfer_id');
        });
        Schema::table('stock_transfers', fn (Blueprint $table) => $table->dropColumn('cancellation_reason'));
    }

    private function movementTypes(array $types): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->enum('movement_type', $types)->change());
        }
    }
};
