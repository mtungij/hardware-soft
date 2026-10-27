<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $payments = ['sale_payments', 'customer_payments', 'supplier_payments', 'expenses', 'customer_receipts', 'customer_deposits', 'customer_material_cash_transactions', 'goods_receipt_additional_costs'];

    public function up(): void
    {
        // Add a canonical catalog without dropping or renaming the legacy document table.
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('type', 30);
                $table->string('display_name');
                foreach (['provider', 'bank_name', 'account_name', 'account_number', 'phone_or_business_number', 'branch_name'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->string('currency', 10)->default('TZS');
                $table->text('instructions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('show_on_quotation')->default(true);
                $table->boolean('show_on_proforma')->default(true);
                $table->boolean('show_on_invoice')->default(true);
                $table->boolean('is_default')->default(false);
                $table->string('legacy_code', 30)->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'legacy_code'], 'pm_company_legacy_unique');
                $table->index(['company_id', 'branch_id', 'is_active'], 'pm_available_index');
            });
        }
        DB::table('company_payment_methods')->orderBy('id')->chunkById(500, function ($rows) {
            DB::table('payment_methods')->insertOrIgnore($rows->map(fn ($row) => (array) $row)->all());
        });
        foreach ($this->payments as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('payment_method', 100)->nullable()->change();
                if (! Schema::hasColumn($name, 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
                }
                if (! Schema::hasColumn($name, 'payment_method_name_snapshot')) {
                    $table->string('payment_method_name_snapshot')->nullable();
                }
                if (! Schema::hasColumn($name, 'payment_method_type_snapshot')) {
                    $table->string('payment_method_type_snapshot', 30)->nullable();
                }
                if (! Schema::hasColumn($name, 'payment_submission_key')) {
                    $table->uuid('payment_submission_key')->nullable();
                }
            });
            // MySQL commits DDL separately. A retry must repair constraints even
            // when a previous attempt already committed their columns.
            if (! Schema::hasIndex($name, ['company_id', 'payment_submission_key'], 'unique')) {
                Schema::table($name, fn (Blueprint $table) => $table->unique(['company_id', 'payment_submission_key'], 'pm_submit_'.substr(sha1($name), 0, 12).'_uq'));
            }
            if (! Schema::hasForeignKey($name, ['payment_method_id'])) {
                Schema::table($name, fn (Blueprint $table) => $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete());
            }
        }
        if (! Schema::hasColumn('sale_payments', 'customer_payment_id')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->foreignId('customer_payment_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        if (! Schema::hasForeignKey('sale_payments', ['customer_payment_id'])) {
            Schema::table('sale_payments', fn (Blueprint $table) => $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->restrictOnDelete());
        }
        foreach (DB::table('companies')->orderBy('id')->cursor() as $company) {
            foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'] as $type => $label) {
                DB::table('payment_methods')->insertOrIgnore([
                    'company_id' => $company->id, 'type' => $type, 'display_name' => $label,
                    'legacy_code' => $type, 'is_active' => true, 'is_default' => $type === 'cash',
                    'show_on_quotation' => false, 'show_on_proforma' => false, 'show_on_invoice' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $id = DB::table('payment_methods')->where('company_id', $company->id)->where('legacy_code', $type)->value('id');
                foreach ($this->payments as $table) {
                    DB::table($table)->where('company_id', $company->id)
                        ->whereIn('payment_method', $type === 'cash' ? ['cash', 'cash_deposit'] : [$type])
                        ->whereNull('payment_method_id')->update([
                            'payment_method_id' => $id, 'payment_method_name_snapshot' => $label,
                            'payment_method_type_snapshot' => $type,
                        ]);
                }
            }
        }
        // Historical credit markers deliberately remain unlinked: they are not receipts of money.
    }

    public function down(): void
    {
        // A rollback must never delete configured methods or payment snapshots.
        throw new RuntimeException('Payment catalog rollback requires a reviewed forward migration; historical payment links must be retained.');
    }
};
