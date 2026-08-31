<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->string('whatsapp_notification_language', 2)->default('en')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_notification_language');
        });
    }
};
