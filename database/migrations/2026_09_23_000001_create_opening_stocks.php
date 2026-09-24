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
        'curing_release_in', 'curing_release_out', 'curing_damage',
    ];

    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $table->date('opening_date');
            $table->string('reference_number', 40);
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_products');
            $table->decimal('total_base_quantity', 18, 4);
            $table->decimal('total_value', 18, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['company_id', 'reference_number'], 'opening_stocks_company_reference_unique');
            $table->index(['company_id', 'branch_id', 'opening_date']);
        });

        Schema::create('opening_stock_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opening_stock_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_conversion_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('transaction_unit_name_snapshot');
            $table->string('transaction_unit_code_snapshot')->nullable();
            $table->decimal('transaction_quantity', 18, 4);
            $table->decimal('conversion_factor_snapshot', 18, 4);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('base_unit_cost', 18, 2);
            $table->decimal('total_cost', 18, 2);
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['opening_stock_id', 'product_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            $this->alterMovementTypes([...self::PREVIOUS_TYPES, 'opening_stock']);
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->enum('movement_type', [...self::PREVIOUS_TYPES, 'opening_stock'])->change());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('opening_stocks') && DB::table('opening_stocks')->exists()) {
            throw new RuntimeException('Cannot remove posted Opening Stock history.');
        }
        if (DB::getDriverName() === 'mysql') {
            $this->alterMovementTypes(self::PREVIOUS_TYPES);
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->enum('movement_type', self::PREVIOUS_TYPES)->change());
        }
        Schema::dropIfExists('opening_stock_lines');
        Schema::dropIfExists('opening_stocks');
    }

    private function alterMovementTypes(array $types): void
    {
        $enum = collect($types)->map(fn (string $type): string => "'".str_replace("'", "''", $type)."'")->implode(',');
        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM({$enum}) NOT NULL");
    }
};
