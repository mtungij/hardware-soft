<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('active_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('version', 50)->nullable();
            $table->decimal('output_quantity', 18, 8)->default(1);
            $table->foreignId('output_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'active_product_id'], 'recipe_one_active_uq');
            $table->unique(['company_id', 'code'], 'recipe_co_code_uq');
            $table->index(['company_id', 'product_id'], 'recipe_co_product_ix');
            $table->index(['company_id', 'status'], 'recipe_co_status_ix');
        });

        Schema::create('production_recipe_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_recipe_id')->constrained('production_recipes')->cascadeOnDelete();
            $table->foreignId('material_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('material_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('cost_type', 20);
            $table->string('cost_name')->nullable();
            $table->string('entry_mode', 20)->default('per_output');
            $table->decimal('source_quantity', 18, 8)->nullable();
            $table->decimal('yield_quantity', 18, 8)->nullable();
            $table->decimal('normalized_quantity', 18, 8)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'production_recipe_id'], 'recipe_item_parent_ix');
            $table->index(['company_id', 'material_product_id'], 'recipe_item_material_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_recipe_items');
        Schema::dropIfExists('production_recipes');
    }
};
