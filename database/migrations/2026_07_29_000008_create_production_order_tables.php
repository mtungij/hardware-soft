<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('raw_material_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('finished_goods_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('production_machine_assignment_id')->nullable()->constrained('production_machine_assignments')->restrictOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('production_recipe_id')->nullable()->constrained('production_recipes')->nullOnDelete();
            $table->string('order_number', 60);
            $table->date('production_date');
            $table->decimal('planned_quantity', 18, 4);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('rejected_quantity', 18, 4)->default(0);
            $table->decimal('total_produced_quantity', 18, 4)->default(0);
            $table->string('status', 30)->default('planned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('posting_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'order_number'], 'prd_order_number_uq');
            $table->unique('production_machine_assignment_id', 'prd_order_assignment_uq');
            $table->unique(['company_id', 'posting_reference'], 'prd_order_posting_uq');
            $table->index(['company_id', 'status'], 'prd_order_status_ix');
            $table->index(['company_id', 'production_date'], 'prd_order_date_ix');
        });

        Schema::create('production_order_recipe_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('source_recipe_id')->nullable()->constrained('production_recipes')->nullOnDelete();
            $table->string('recipe_name');
            $table->string('recipe_code')->nullable();
            $table->string('recipe_version', 50)->nullable();
            $table->decimal('recipe_output_quantity', 24, 12);
            $table->foreignId('recipe_output_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique('production_order_id', 'prd_snapshot_order_uq');
        });

        Schema::create('production_order_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('source_recipe_item_id')->nullable()->constrained('production_recipe_items')->nullOnDelete();
            $table->string('line_type', 30);
            $table->foreignId('material_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('name');
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('normalized_quantity_per_output', 24, 12)->nullable();
            $table->decimal('planned_quantity', 24, 12)->nullable();
            $table->decimal('actual_quantity', 24, 12)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('planned_cost', 18, 4)->default(0);
            $table->decimal('actual_cost', 18, 4)->default(0);
            $table->string('entry_mode', 20)->nullable();
            $table->decimal('source_quantity', 24, 12)->nullable();
            $table->decimal('source_yield_quantity', 24, 12)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'production_order_id'], 'prd_material_order_ix');
            $table->index(['company_id', 'material_product_id'], 'prd_material_product_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_order_recipe_snapshots');
        Schema::dropIfExists('production_orders');
    }
};
