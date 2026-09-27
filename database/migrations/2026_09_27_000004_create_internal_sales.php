<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MOVEMENT_TYPES = [
        'purchase_in', 'purchase_receipt', 'purchase_receipt_reversal', 'transfer_in',
        'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out',
        'return_in', 'direct_stock_in', 'production_output', 'production_consumption',
        'curing_release_in', 'curing_release_out', 'curing_damage', 'opening_stock',
    ];

    public function up(): void
    {
        Schema::create('internal_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('internal_sale_number', 50);
            $table->date('sale_date');
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('total_internal_value', 18, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'internal_sale_number'], 'internal_sales_company_number_unique');
            $table->index(['company_id', 'branch_id', 'sale_date', 'status']);
        });

        Schema::create('internal_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('internal_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_conversion_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('transaction_unit_name_snapshot');
            $table->string('transaction_unit_code_snapshot')->nullable();
            $table->decimal('transaction_quantity', 18, 4);
            $table->decimal('conversion_factor_snapshot', 18, 4);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('internal_unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('company_base_unit_cost', 18, 6)->nullable();
            $table->decimal('source_acquisition_base_unit_cost', 18, 6)->nullable();
            $table->decimal('destination_acquisition_base_unit_cost', 18, 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('location_acquisition_unit_cost', 18, 6)->nullable();
        });
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('location_base_unit_cost', 18, 6)->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            $this->alterMovementTypes([...self::MOVEMENT_TYPES, 'internal_sale_out', 'internal_sale_in']);
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->enum('movement_type', [...self::MOVEMENT_TYPES, 'internal_sale_out', 'internal_sale_in'])->change());
        }
    }

    public function down(): void
    {
        if (DB::table('internal_sales')->where('status', 'completed')->exists()) {
            throw new RuntimeException('Cannot remove completed Internal Sale history.');
        }
        if (DB::getDriverName() === 'mysql') {
            $this->alterMovementTypes(self::MOVEMENT_TYPES);
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->enum('movement_type', self::MOVEMENT_TYPES)->change());
        }
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('location_base_unit_cost'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('location_acquisition_unit_cost'));
        Schema::dropIfExists('internal_sale_items');
        Schema::dropIfExists('internal_sales');
    }

    private function alterMovementTypes(array $types): void
    {
        $enum = collect($types)->map(fn (string $type): string => "'".str_replace("'", "''", $type)."'")->implode(',');
        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM({$enum}) NOT NULL");
    }
};
