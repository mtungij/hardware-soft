<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            $table->decimal('goods_value', 18, 2)->nullable();
            $table->decimal('additional_cost_total', 18, 2)->nullable();
            $table->decimal('landed_total', 18, 2)->nullable();
        });

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->decimal('supplier_line_cost', 18, 2)->nullable();
            $table->decimal('allocated_additional_cost', 18, 2)->nullable();
            $table->decimal('landed_line_cost', 18, 2)->nullable();
            $table->decimal('landed_unit_cost', 18, 4)->nullable();
            $table->decimal('landed_base_unit_cost', 18, 6)->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 6)->nullable()->change();
        });

        Schema::create('goods_receipt_additional_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receiving_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_cost_type_id')->constrained()->restrictOnDelete();
            $table->string('cost_type_name_snapshot', 100);
            $table->decimal('amount', 18, 2);
            $table->string('payee')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_additional_costs');
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 2)->nullable()->change();
        });
        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->dropColumn(['supplier_line_cost', 'allocated_additional_cost', 'landed_line_cost', 'landed_unit_cost', 'landed_base_unit_cost']);
        });
        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            $table->dropColumn(['goods_value', 'additional_cost_total', 'landed_total']);
        });
    }
};
