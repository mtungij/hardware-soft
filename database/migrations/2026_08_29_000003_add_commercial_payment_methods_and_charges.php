<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 30);
            $table->string('display_name');
            $table->string('provider')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('phone_or_business_number')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('show_on_quotation')->default(true);
            $table->boolean('show_on_proforma')->default(true);
            $table->boolean('show_on_invoice')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active', 'sort_order'], 'cpm_scope_active_ix');
            $table->foreign('company_id', 'cpm_company_fk')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('additional_charge_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'act_company_name_uq');
            $table->index(['company_id', 'is_active', 'sort_order'], 'act_scope_active_ix');
            $table->foreign('company_id', 'act_company_fk')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->decimal('additional_charge_amount', 15, 2)->default(0)->after('tax_amount');
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('additional_charge_amount', 15, 2)->default(0)->after('tax_amount');
        });

        Schema::create('quotation_additional_charges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('additional_charge_type_id')->nullable();
            $table->string('charge_name_snapshot');
            $table->text('description_snapshot')->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['quotation_id', 'sort_order'], 'qac_quote_order_ix');
            $table->foreign('company_id', 'qac_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('quotation_id', 'qac_quote_fk')->references('id')->on('quotations')->cascadeOnDelete();
            $table->foreign('additional_charge_type_id', 'qac_type_fk')->references('id')->on('additional_charge_types')->nullOnDelete();
        });

        Schema::create('sale_additional_charges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('quotation_additional_charge_id')->nullable();
            $table->unsignedBigInteger('additional_charge_type_id')->nullable();
            $table->string('charge_name_snapshot');
            $table->text('description_snapshot')->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['sale_id', 'sort_order'], 'sac_sale_order_ix');
            $table->foreign('company_id', 'sac_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('sale_id', 'sac_sale_fk')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('quotation_additional_charge_id', 'sac_quote_charge_fk')->references('id')->on('quotation_additional_charges')->nullOnDelete();
            $table->foreign('additional_charge_type_id', 'sac_type_fk')->references('id')->on('additional_charge_types')->nullOnDelete();
        });

        Schema::create('commercial_configuration_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'subject_type', 'subject_id'], 'cce_subject_ix');
            $table->foreign('company_id', 'cce_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id', 'cce_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        $permissions = collect([
            'payment_methods.view', 'payment_methods.manage',
            'additional_charge_types.view', 'additional_charge_types.manage', 'commercial_charges.apply',
        ])->map(fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now])->all();
        DB::table('permissions')->insertOrIgnore($permissions);
        $permissionIds = DB::table('permissions')->whereIn('name', collect($permissions)->pluck('name'))->pluck('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'Admin', 'Manager'])->pluck('id');
        $rows = $roleIds->crossJoin($permissionIds)->map(fn (array $pair): array => ['role_id' => $pair[0], 'permission_id' => $pair[1]])->all();
        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_configuration_events');
        Schema::dropIfExists('sale_additional_charges');
        Schema::dropIfExists('quotation_additional_charges');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('additional_charge_amount'));
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn('additional_charge_amount'));
        Schema::dropIfExists('additional_charge_types');
        Schema::dropIfExists('company_payment_methods');
    }
};
