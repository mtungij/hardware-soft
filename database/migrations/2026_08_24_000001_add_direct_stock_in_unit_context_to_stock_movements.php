<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_unit_conversion_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('transaction_unit_id')->nullable()->after('product_unit_conversion_id');
            $table->string('transaction_unit_name_snapshot')->nullable()->after('transaction_unit_id');
            $table->string('transaction_unit_code_snapshot')->nullable()->after('transaction_unit_name_snapshot');
            $table->decimal('transaction_quantity', 18, 4)->nullable()->after('quantity_out');
            $table->decimal('conversion_factor_snapshot', 18, 4)->nullable()->after('transaction_quantity');
            $table->decimal('transaction_unit_cost', 18, 2)->nullable()->after('unit_cost');
            $table->decimal('transaction_unit_price', 18, 2)->nullable()->after('unit_price');
            $table->uuid('idempotency_key')->nullable()->after('posting_reference');

            $table->foreign('product_unit_conversion_id', 'sm_unit_conversion_fk')
                ->references('id')->on('product_unit_conversions')->nullOnDelete();
            $table->foreign('transaction_unit_id', 'sm_transaction_unit_fk')
                ->references('id')->on('units')->nullOnDelete();
            $table->unique(['company_id', 'idempotency_key'], 'sm_company_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('sm_company_idem_uq');
            $table->dropForeign('sm_unit_conversion_fk');
            $table->dropForeign('sm_transaction_unit_fk');
            $table->dropColumn([
                'product_unit_conversion_id',
                'transaction_unit_id',
                'transaction_unit_name_snapshot',
                'transaction_unit_code_snapshot',
                'transaction_quantity',
                'conversion_factor_snapshot',
                'transaction_unit_cost',
                'transaction_unit_price',
                'idempotency_key',
            ]);
        });
    }
};
