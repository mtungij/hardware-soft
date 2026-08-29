<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('source_type', 30)->default('customer_request')->after('document_type');
            $table->uuid('creation_key')->nullable()->after('source_type');
            $table->unique(['company_id', 'creation_key'], 'quote_company_create_uq');
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('source_type', 30)->default('quotation')->after('quotation_id');
        });

        DB::table('quotations')->whereNull('customer_purchase_request_id')->update(['source_type' => 'staff_created']);
        DB::table('sales_invoices')->whereNull('quotation_id')->update(['source_type' => 'direct_sale']);
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn('source_type');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropUnique('quote_company_create_uq');
            $table->dropColumn(['source_type', 'creation_key']);
        });
    }
};
