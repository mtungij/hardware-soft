<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('sale_number');
            $table->unique(
                ['company_id', 'idempotency_key'],
                'sales_company_idempotency_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique('sales_company_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
