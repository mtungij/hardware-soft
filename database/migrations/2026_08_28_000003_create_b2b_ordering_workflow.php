<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('customer_account_id');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->string('request_number', 40);
            $table->string('status', 30)->default('pending');
            $table->string('submission_key', 100);
            $table->text('customer_notes')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'request_number'], 'cpr_company_number_uq');
            $table->unique(['customer_account_id', 'submission_key'], 'cpr_account_submit_uq');
            $table->index(['company_id', 'branch_id', 'status'], 'cpr_scope_status_ix');
            $table->foreign('company_id', 'cpr_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id', 'cpr_branch_fk')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id', 'cpr_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('customer_account_id', 'cpr_account_fk')->references('id')->on('customer_accounts')->restrictOnDelete();
            $table->foreign('reviewed_by', 'cpr_reviewer_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('sale_id', 'cpr_sale_fk')->references('id')->on('sales')->nullOnDelete();
        });

        Schema::create('customer_purchase_request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_purchase_request_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('base_unit_id');
            $table->unsignedBigInteger('transaction_unit_id');
            $table->unsignedBigInteger('product_unit_conversion_id')->nullable();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot')->nullable();
            $table->string('base_unit_name_snapshot');
            $table->string('transaction_unit_name_snapshot');
            $table->decimal('transaction_quantity', 15, 4);
            $table->decimal('conversion_factor_snapshot', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('display_unit_price_snapshot', 15, 2)->nullable();
            $table->text('customer_notes')->nullable();
            $table->timestamps();

            $table->index(['customer_purchase_request_id', 'product_id'], 'cpri_request_product_ix');
            $table->foreign('company_id', 'cpri_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_purchase_request_id', 'cpri_request_fk')->references('id')->on('customer_purchase_requests')->cascadeOnDelete();
            $table->foreign('product_id', 'cpri_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('base_unit_id', 'cpri_base_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('transaction_unit_id', 'cpri_tx_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('product_unit_conversion_id', 'cpri_conversion_fk')->references('id')->on('product_unit_conversions')->nullOnDelete();
        });

        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('customer_purchase_request_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('converted_sale_id')->nullable();
            $table->string('quotation_number', 40);
            $table->string('document_type', 20)->default('quotation');
            $table->string('status', 30)->default('draft');
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'quotation_number'], 'quote_company_number_uq');
            $table->index(['company_id', 'branch_id', 'status'], 'quote_scope_status_ix');
            $table->foreign('company_id', 'quote_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id', 'quote_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('customer_id', 'quote_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('customer_purchase_request_id', 'quote_request_fk')->references('id')->on('customer_purchase_requests')->nullOnDelete();
            $table->foreign('created_by', 'quote_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('converted_sale_id', 'quote_sale_fk')->references('id')->on('sales')->nullOnDelete();
        });

        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('base_unit_id');
            $table->unsignedBigInteger('transaction_unit_id');
            $table->unsignedBigInteger('product_unit_conversion_id')->nullable();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot')->nullable();
            $table->string('base_unit_name_snapshot');
            $table->string('transaction_unit_name_snapshot');
            $table->decimal('transaction_quantity', 15, 4);
            $table->decimal('conversion_factor_snapshot', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_per_unit', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index(['quotation_id', 'product_id'], 'qi_quote_product_ix');
            $table->foreign('company_id', 'qi_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('quotation_id', 'qi_quote_fk')->references('id')->on('quotations')->cascadeOnDelete();
            $table->foreign('product_id', 'qi_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('base_unit_id', 'qi_base_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('transaction_unit_id', 'qi_tx_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $table->foreign('product_unit_conversion_id', 'qi_conversion_fk')->references('id')->on('product_unit_conversions')->nullOnDelete();
        });

        Schema::create('b2b_document_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('document_type', 30);
            $table->unsignedBigInteger('document_id');
            $table->string('event', 40);
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'document_id'], 'b2be_document_ix');
            $table->foreign('company_id', 'b2be_company_fk')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->string('invoice_number', 40);
            $table->string('pdf_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number'], 'si_company_number_uq');
            $table->unique('sale_id', 'si_sale_uq');
            $table->foreign('company_id', 'si_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('sale_id', 'si_sale_fk')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('customer_id', 'si_customer_fk')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('quotation_id', 'si_quote_fk')->references('id')->on('quotations')->nullOnDelete();
        });

        $now = now();
        $permissions = collect([
            'customer_requests.view', 'customer_requests.review', 'customer_requests.create_quotation', 'customer_requests.convert_to_sale',
            'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.send', 'quotations.cancel', 'quotations.convert',
            'invoices.view', 'invoices.send', 'invoices.export',
        ])->map(fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now])->all();
        DB::table('permissions')->insertOrIgnore($permissions);

        $permissionIds = DB::table('permissions')->whereIn('name', collect($permissions)->pluck('name'))->pluck('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'Admin', 'Manager'])->pluck('id');
        $assignments = $roleIds->crossJoin($permissionIds)->map(fn (array $pair): array => ['role_id' => $pair[0], 'permission_id' => $pair[1]])->all();
        if ($assignments !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($assignments);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('b2b_document_events');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('customer_purchase_request_items');
        Schema::dropIfExists('customer_purchase_requests');
    }
};
