<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_costings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'poc_company_fk')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders', indexName: 'poc_order_fk')->restrictOnDelete();
            $table->string('costing_number', 60);
            $table->string('currency_code', 10)->nullable();
            $table->decimal('planned_inventory_material_cost', 24, 4)->default(0);
            $table->decimal('actual_inventory_material_cost', 24, 4)->default(0);
            $table->decimal('planned_non_inventory_cost', 24, 4)->default(0);
            $table->decimal('actual_non_inventory_cost', 24, 4)->default(0);
            $table->decimal('total_planned_cost', 24, 4)->default(0);
            $table->decimal('total_actual_cost', 24, 4)->default(0);
            $table->decimal('planned_quantity', 24, 12);
            $table->decimal('total_produced_quantity', 24, 12);
            $table->decimal('accepted_quantity', 24, 12);
            $table->decimal('rejected_quantity', 24, 12);
            $table->decimal('curing_damaged_quantity', 24, 12)->default(0);
            $table->decimal('released_quantity', 24, 12)->default(0);
            $table->decimal('cost_per_planned_unit', 24, 8)->nullable();
            $table->decimal('cost_per_total_produced_unit', 24, 8)->nullable();
            $table->decimal('cost_per_accepted_unit', 24, 8)->nullable();
            $table->decimal('cost_per_released_unit', 24, 8)->nullable();
            $table->decimal('rejected_loss_cost', 24, 4)->default(0);
            $table->decimal('curing_damage_loss_cost', 24, 4)->default(0);
            $table->decimal('total_loss_cost', 24, 4)->default(0);
            $table->decimal('cost_variance', 24, 4)->default(0);
            $table->decimal('variance_percentage', 12, 4)->nullable();
            $table->decimal('output_variance', 24, 12)->default(0);
            $table->decimal('yield_variance', 24, 12)->default(0);
            $table->boolean('has_missing_cost')->default(false);
            $table->json('warnings')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('calculation_version')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users', indexName: 'poc_calculated_by_fk')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users', indexName: 'poc_finalized_by_fk')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('production_order_id', 'prd_cost_order_uq');
            $table->unique(['company_id', 'costing_number'], 'prd_cost_number_uq');
            $table->index(['company_id', 'status'], 'prd_cost_status_ix');
        });

        Schema::create('production_order_costing_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pocl_company_fk')->cascadeOnDelete();
            $table->foreignId('production_order_costing_id')->constrained('production_order_costings', indexName: 'pocl_costing_fk')->cascadeOnDelete();
            $table->foreignId('production_order_material_id')->nullable()->constrained('production_order_materials', indexName: 'pocl_order_material_fk')->restrictOnDelete();
            $table->string('line_type', 40);
            $table->string('cost_basis', 30);
            $table->string('name');
            $table->foreignId('product_id')->nullable()->constrained('products', indexName: 'pocl_product_fk')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units', indexName: 'pocl_unit_fk')->restrictOnDelete();
            $table->decimal('planned_quantity', 24, 12)->nullable();
            $table->decimal('actual_quantity', 24, 12)->nullable();
            $table->decimal('planned_unit_cost', 24, 8)->nullable();
            $table->decimal('actual_unit_cost', 24, 8)->nullable();
            $table->decimal('planned_total_cost', 24, 4)->default(0);
            $table->decimal('actual_total_cost', 24, 4)->default(0);
            $table->decimal('quantity_variance', 24, 12)->nullable();
            $table->decimal('cost_variance', 24, 4)->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('production_order_material_id', 'prd_cost_line_material_uq');
            $table->index(['production_order_costing_id', 'line_type'], 'prd_cost_line_type_ix');
        });

        Schema::create('production_order_costing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'poce_company_fk')->cascadeOnDelete();
            $table->foreignId('production_order_costing_id')->constrained('production_order_costings', indexName: 'poce_costing_fk')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'poce_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_order_costing_id', 'event_type'], 'prd_cost_event_type_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_costing_events');
        Schema::dropIfExists('production_order_costing_lines');
        Schema::dropIfExists('production_order_costings');
    }
};
