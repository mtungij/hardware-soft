<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_setting_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 80);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'wsa_company_date_ix');
            $table->foreign('company_id', 'wsa_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id', 'wsa_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_setting_audits');
    }
};
