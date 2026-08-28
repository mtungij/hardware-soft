<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('requires_quality_control')->default(false)->after('curing_notes');
            $table->boolean('requires_pre_release_inspection')->default(false)->after('requires_quality_control');
            $table->text('quality_notes')->nullable()->after('requires_pre_release_inspection');
        });

        Schema::create('production_quality_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pqp_company_fk')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', indexName: 'pqp_product_fk')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 60)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('inspection_stage', 40);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pqp_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'pqp_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'product_id', 'inspection_stage', 'status'], 'qc_plan_active_ix');
            $table->index(['company_id', 'code'], 'qc_plan_code_ix');
        });

        Schema::create('production_quality_plan_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pqpc_company_fk')->cascadeOnDelete();
            $table->foreignId('production_quality_plan_id')->constrained('production_quality_plans', indexName: 'pqpc_plan_fk')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('check_type', 30);
            $table->foreignId('unit_id')->nullable()->constrained('units', indexName: 'pqpc_unit_fk')->nullOnDelete();
            $table->decimal('minimum_value', 24, 8)->nullable();
            $table->decimal('maximum_value', 24, 8)->nullable();
            $table->decimal('target_value', 24, 8)->nullable();
            $table->json('allowed_options')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('critical')->default(false);
            $table->string('acceptance_rule', 30);
            $table->unsignedInteger('sort_order')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['production_quality_plan_id', 'sort_order'], 'qc_check_sort_ix');
        });

        Schema::create('production_quality_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pqi_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained(indexName: 'pqi_branch_fk')->nullOnDelete();
            $table->foreignId('production_quality_plan_id')->constrained('production_quality_plans', indexName: 'pqi_plan_fk')->restrictOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders', indexName: 'pqi_order_fk')->restrictOnDelete();
            $table->foreignId('production_curing_batch_id')->nullable()->constrained('production_curing_batches', indexName: 'pqi_curing_batch_fk')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', indexName: 'pqi_product_fk')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines', indexName: 'pqi_machine_fk')->nullOnDelete();
            $table->string('inspection_number', 60);
            $table->string('inspection_stage', 40);
            $table->decimal('applicable_quantity', 24, 12)->nullable();
            $table->decimal('inspected_quantity', 24, 12)->nullable();
            $table->decimal('sample_quantity', 24, 12)->nullable();
            $table->decimal('passed_quantity', 24, 12)->nullable();
            $table->decimal('failed_quantity', 24, 12)->nullable();
            $table->string('result', 20)->default('pending');
            $table->string('approval_status', 20)->default('pending');
            $table->timestamp('inspected_at');
            $table->foreignId('inspected_by')->constrained('users', indexName: 'pqi_inspected_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'pqi_approved_by_fk')->nullOnDelete();
            $table->text('approval_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('corrective_action')->nullable();
            $table->boolean('retest_required')->default(false);
            $table->foreignId('supersedes_inspection_id')->nullable()->constrained('production_quality_inspections', indexName: 'pqi_supersedes_fk')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'inspection_number'], 'qc_inspection_number_uq');
            $table->index(['company_id', 'result', 'approval_status'], 'qc_inspection_state_ix');
            $table->index(['production_curing_batch_id', 'inspection_stage'], 'qc_inspection_batch_ix');
            $table->index(['production_order_id', 'inspection_stage'], 'qc_inspection_order_ix');
        });

        Schema::create('production_quality_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pqir_company_fk')->cascadeOnDelete();
            $table->foreignId('production_quality_inspection_id')->constrained('production_quality_inspections', indexName: 'pqir_inspection_fk')->restrictOnDelete();
            $table->foreignId('production_quality_plan_check_id')->nullable()->constrained('production_quality_plan_checks', indexName: 'pqir_plan_check_fk')->nullOnDelete();
            $table->string('check_name');
            $table->string('check_type', 30);
            $table->string('acceptance_rule', 30);
            $table->foreignId('unit_id')->nullable()->constrained('units', indexName: 'pqir_unit_fk')->nullOnDelete();
            $table->decimal('minimum_value', 24, 8)->nullable();
            $table->decimal('maximum_value', 24, 8)->nullable();
            $table->decimal('target_value', 24, 8)->nullable();
            $table->json('allowed_options')->nullable();
            $table->decimal('numeric_value', 24, 8)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->text('text_value')->nullable();
            $table->string('selected_value')->nullable();
            $table->string('result', 20)->default('pending');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->text('inspector_comment')->nullable();
            $table->timestamps();
            $table->index(['production_quality_inspection_id', 'result'], 'qc_result_inspection_ix');
        });

        Schema::create('production_quality_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pqh_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained(indexName: 'pqh_branch_fk')->nullOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders', indexName: 'pqh_order_fk')->restrictOnDelete();
            $table->foreignId('production_curing_batch_id')->nullable()->constrained('production_curing_batches', indexName: 'pqh_curing_batch_fk')->restrictOnDelete();
            $table->foreignId('production_quality_inspection_id')->nullable()->constrained('production_quality_inspections', indexName: 'pqh_inspection_fk')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', indexName: 'pqh_product_fk')->restrictOnDelete();
            $table->string('hold_number', 60);
            $table->text('reason');
            $table->string('status', 20)->default('active');
            $table->decimal('held_quantity', 24, 12)->nullable();
            $table->timestamp('placed_at');
            $table->foreignId('placed_by')->constrained('users', indexName: 'pqh_placed_by_fk')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users', indexName: 'pqh_released_by_fk')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'hold_number'], 'qc_hold_number_uq');
            $table->index(['company_id', 'status'], 'qc_hold_status_ix');
            $table->index(['production_curing_batch_id', 'status'], 'qc_hold_batch_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_quality_holds');
        Schema::dropIfExists('production_quality_inspection_results');
        Schema::dropIfExists('production_quality_inspections');
        Schema::dropIfExists('production_quality_plan_checks');
        Schema::dropIfExists('production_quality_plans');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['requires_quality_control', 'requires_pre_release_inspection', 'quality_notes']);
        });
    }
};
