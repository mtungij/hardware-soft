<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_material_accounts')) {
            Schema::create('customer_material_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('reference_number', 60);
                $table->string('project_name');
                $table->text('description')->nullable();
                $table->string('project_location')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamps();

                $table->foreign('company_id', 'cma_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id', 'cma_branch_fk')->references('id')->on('branches')->restrictOnDelete();
                $table->foreign('customer_id', 'cma_customer_fk')->references('id')->on('customers')->restrictOnDelete();
                $table->foreign('created_by', 'cma_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by', 'cma_updated_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('closed_by', 'cma_closed_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->unique(['company_id', 'reference_number'], 'cma_reference_uq');
                $table->index(['company_id', 'branch_id', 'status'], 'cma_branch_status_ix');
                $table->index(['company_id', 'customer_id'], 'cma_customer_ix');
            });
        }

        if (! Schema::hasTable('customer_material_plan_lines')) {
            Schema::create('customer_material_plan_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('customer_material_account_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_unit_conversion_id')->nullable();
                $table->unsignedBigInteger('transaction_unit_id');
                $table->unsignedBigInteger('base_unit_id');
                $table->string('product_name_snapshot');
                $table->string('unit_name_snapshot');
                $table->string('unit_code_snapshot')->nullable();
                $table->string('base_unit_name_snapshot');
                $table->string('base_unit_code_snapshot')->nullable();
                $table->decimal('conversion_factor_snapshot', 24, 12);
                $table->decimal('planned_quantity', 24, 12);
                $table->decimal('planned_base_quantity', 24, 12);
                $table->decimal('agreed_unit_price', 18, 2);
                $table->decimal('planned_line_total', 18, 2);
                $table->unsignedInteger('revision')->default(1);
                $table->text('amendment_reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->foreign('company_id', 'cmpl_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('customer_material_account_id', 'cmpl_account_fk')->references('id')->on('customer_material_accounts')->restrictOnDelete();
                $table->foreign('product_id', 'cmpl_product_fk')->references('id')->on('products')->restrictOnDelete();
                $table->foreign('product_unit_conversion_id', 'cmpl_conversion_fk')->references('id')->on('product_unit_conversions')->restrictOnDelete();
                $table->foreign('transaction_unit_id', 'cmpl_tx_unit_fk')->references('id')->on('units')->restrictOnDelete();
                $table->foreign('base_unit_id', 'cmpl_base_unit_fk')->references('id')->on('units')->restrictOnDelete();
                $table->foreign('created_by', 'cmpl_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by', 'cmpl_updated_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->index(['customer_material_account_id', 'product_id'], 'cmpl_account_product_ix');
            });
        }

        Schema::create('customer_material_cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_material_account_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('transaction_type', 20);
            $table->string('reference_number', 60);
            $table->string('idempotency_key', 100);
            $table->decimal('amount', 18, 2);
            $table->string('payment_method', 30);
            $table->string('payment_reference')->nullable();
            $table->timestamp('transacted_at');
            $table->text('notes')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'cmct_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_material_account_id', 'cmct_account_fk')->references('id')->on('customer_material_accounts')->restrictOnDelete();
            $table->foreign('branch_id', 'cmct_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('received_by', 'cmct_received_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['company_id', 'reference_number'], 'cmct_reference_uq');
            $table->unique(['company_id', 'idempotency_key'], 'cmct_idem_uq');
            $table->index(['customer_material_account_id', 'transaction_type'], 'cmct_account_type_ix');
        });

        Schema::create('customer_material_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_material_account_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->string('reference_number', 60);
            $table->string('posting_reference', 100);
            $table->string('idempotency_key', 100);
            $table->decimal('total_value', 18, 2);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->string('collected_by')->nullable();
            $table->timestamp('issued_at');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'cmi_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_material_account_id', 'cmi_account_fk')->references('id')->on('customer_material_accounts')->restrictOnDelete();
            $table->foreign('branch_id', 'cmi_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('stock_location_id', 'cmi_location_fk')->references('id')->on('stock_locations')->restrictOnDelete();
            $table->foreign('issued_by', 'cmi_issued_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['company_id', 'reference_number'], 'cmi_reference_uq');
            $table->unique(['company_id', 'posting_reference'], 'cmi_posting_uq');
            $table->unique(['company_id', 'idempotency_key'], 'cmi_idem_uq');
            $table->index(['customer_material_account_id', 'issued_at'], 'cmi_account_date_ix');
        });

        Schema::create('customer_material_issue_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_material_issue_id');
            $table->unsignedBigInteger('customer_material_plan_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('transaction_unit_id');
            $table->unsignedBigInteger('base_unit_id');
            $table->string('product_name_snapshot');
            $table->string('unit_name_snapshot');
            $table->string('unit_code_snapshot')->nullable();
            $table->string('base_unit_name_snapshot');
            $table->string('base_unit_code_snapshot')->nullable();
            $table->decimal('conversion_factor_snapshot', 24, 12);
            $table->decimal('quantity', 24, 12);
            $table->decimal('base_quantity', 24, 12);
            $table->decimal('agreed_unit_price', 18, 2);
            $table->decimal('line_value', 18, 2);
            $table->decimal('base_unit_cost', 18, 4)->default(0);
            $table->decimal('line_cost', 18, 2)->default(0);
            $table->timestamps();
            $table->foreign('company_id', 'cmil_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_material_issue_id', 'cmil_issue_fk')->references('id')->on('customer_material_issues')->restrictOnDelete();
            $table->foreign('customer_material_plan_line_id', 'cmil_plan_fk')->references('id')->on('customer_material_plan_lines')->restrictOnDelete();
            $table->foreign('product_id', 'cmil_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('transaction_unit_id', 'cmil_tx_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('base_unit_id', 'cmil_base_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->index(['customer_material_plan_line_id', 'product_id'], 'cmil_plan_product_ix');
        });

        Schema::create('customer_material_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_material_account_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('transaction_type', 30);
            $table->string('reference_number', 60);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->nullableMorphs('source');
            $table->string('description');
            $table->text('notes')->nullable();
            $table->timestamp('transacted_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'cmt_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_material_account_id', 'cmt_account_fk')->references('id')->on('customer_material_accounts')->restrictOnDelete();
            $table->foreign('branch_id', 'cmt_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('created_by', 'cmt_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['source_type', 'source_id'], 'cmt_source_uq');
            $table->index(['customer_material_account_id', 'transacted_at'], 'cmt_account_date_ix');
        });

        Schema::create('customer_material_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_material_account_id');
            $table->string('action', 50);
            $table->nullableMorphs('subject');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'cmaudit_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_material_account_id', 'cmaudit_account_fk')->references('id')->on('customer_material_accounts')->restrictOnDelete();
            $table->foreign('actor_id', 'cmaudit_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['customer_material_account_id', 'created_at'], 'cmaudit_account_date_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_material_audits');
        Schema::dropIfExists('customer_material_transactions');
        Schema::dropIfExists('customer_material_issue_lines');
        Schema::dropIfExists('customer_material_issues');
        Schema::dropIfExists('customer_material_cash_transactions');
        Schema::dropIfExists('customer_material_plan_lines');
        Schema::dropIfExists('customer_material_accounts');
    }
};
