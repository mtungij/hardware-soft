<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('quotation_template_key', 40)->default('classic');
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('quotation_template_key', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn('quotation_template_key'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('quotation_template_key'));
    }
};
