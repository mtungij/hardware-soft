<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Hardex POS');
            $table->string('company_logo')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('company_email')->nullable();
            $table->text('company_address')->nullable();
            $table->string('currency')->default('TZS');
            $table->text('receipt_footer_text')->nullable();
            $table->boolean('tax_enabled')->default(false);
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('theme_color')->default('#f97316');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
