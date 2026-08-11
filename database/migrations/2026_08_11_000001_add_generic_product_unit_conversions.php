<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 4);
            $table->decimal('retail_price', 18, 2)->nullable();
            $table->decimal('wholesale_price', 18, 2)->nullable();
            $table->decimal('purchase_price', 18, 2)->nullable();
            $table->boolean('can_purchase')->default(false);
            $table->boolean('can_sell')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'unit_id'], 'product_unit_conversion_unique');
            $table->index(['company_id', 'product_id', 'active']);
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->foreignId('product_unit_conversion_id')->nullable()->after('product_id')->constrained('product_unit_conversions')->nullOnDelete();
            $table->string('purchase_unit_name_snapshot')->nullable()->after('purchase_conversion_factor');
            $table->string('purchase_unit_code_snapshot')->nullable()->after('purchase_unit_name_snapshot');
            $table->string('stock_unit_name_snapshot')->nullable()->after('purchase_unit_code_snapshot');
            $table->string('stock_unit_code_snapshot')->nullable()->after('stock_unit_name_snapshot');
            $table->decimal('base_ordered_quantity', 18, 4)->nullable()->after('ordered_quantity');
            $table->decimal('base_received_quantity', 18, 4)->default(0)->after('received_quantity');
        });

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->string('purchase_unit_name_snapshot')->nullable()->after('stock_unit_id');
            $table->string('purchase_unit_code_snapshot')->nullable()->after('purchase_unit_name_snapshot');
            $table->string('stock_unit_name_snapshot')->nullable()->after('purchase_unit_code_snapshot');
            $table->string('stock_unit_code_snapshot')->nullable()->after('stock_unit_name_snapshot');
            $table->decimal('conversion_factor_snapshot', 18, 4)->default(1)->after('stock_unit_code_snapshot');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('product_unit_conversion_id')->nullable()->after('product_id')->constrained('product_unit_conversions')->nullOnDelete();
            $table->string('selling_unit_name_snapshot')->nullable()->after('conversion_factor');
            $table->string('selling_unit_code_snapshot')->nullable()->after('selling_unit_name_snapshot');
            $table->string('base_unit_name_snapshot')->nullable()->after('selling_unit_code_snapshot');
            $table->string('base_unit_code_snapshot')->nullable()->after('base_unit_name_snapshot');
            $table->decimal('conversion_factor_to_base', 18, 4)->nullable()->after('base_unit_code_snapshot');
            $table->decimal('base_unit_cost', 18, 4)->nullable()->after('base_unit_code_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_unit_conversion_id');
            $table->dropColumn(['selling_unit_name_snapshot', 'selling_unit_code_snapshot', 'base_unit_name_snapshot', 'base_unit_code_snapshot', 'conversion_factor_to_base', 'base_unit_cost']);
        });

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            $table->dropColumn(['purchase_unit_name_snapshot', 'purchase_unit_code_snapshot', 'stock_unit_name_snapshot', 'stock_unit_code_snapshot', 'conversion_factor_snapshot']);
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_unit_conversion_id');
            $table->dropColumn(['purchase_unit_name_snapshot', 'purchase_unit_code_snapshot', 'stock_unit_name_snapshot', 'stock_unit_code_snapshot', 'base_ordered_quantity', 'base_received_quantity']);
        });

        Schema::dropIfExists('product_unit_conversions');
    }
};
