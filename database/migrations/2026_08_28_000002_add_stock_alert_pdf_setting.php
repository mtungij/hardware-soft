<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existingIds = DB::table('company_whatsapp_settings')->pluck('id');

        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->boolean('attach_stock_alert_pdf')->default(true)->after('low_stock_cooldown_hours');
        });

        if ($existingIds->isNotEmpty()) {
            DB::table('company_whatsapp_settings')->whereIn('id', $existingIds)->update(['attach_stock_alert_pdf' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->dropColumn('attach_stock_alert_pdf');
        });
    }
};
