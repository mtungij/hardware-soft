<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->boolean('attach_daily_summary_pdf')->default(false)->after('daily_summary_time');
        });
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->dropColumn('attach_daily_summary_pdf');
        });
    }
};
