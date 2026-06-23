<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('business_type');
            $table->string('tin_number')->nullable();
            $table->string('vrn_number')->nullable();
            $table->string('phone');
            $table->string('whatsapp_number');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('country')->default('Tanzania');
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('currency')->default('TZS');
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('language')->default('sw');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
