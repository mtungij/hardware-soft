<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->boolean('debt_reminders_enabled')->default(false)->after('attach_daily_summary_pdf');
            $table->boolean('debt_due_tomorrow_enabled')->default(true)->after('debt_reminders_enabled');
            $table->boolean('debt_due_today_enabled')->default(true)->after('debt_due_tomorrow_enabled');
            $table->boolean('debt_overdue_enabled')->default(true)->after('debt_due_today_enabled');
            $table->time('debt_reminder_time')->default('08:00')->after('debt_overdue_enabled');
            $table->unsignedSmallInteger('debt_overdue_interval_days')->default(3)->after('debt_reminder_time');
            $table->boolean('attach_debt_summary_pdf')->default(false)->after('debt_overdue_interval_days');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('whatsapp_debt_reminders_enabled')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'debt_reminders_enabled',
                'debt_due_tomorrow_enabled',
                'debt_due_today_enabled',
                'debt_overdue_enabled',
                'debt_reminder_time',
                'debt_overdue_interval_days',
                'attach_debt_summary_pdf',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_debt_reminders_enabled');
        });
    }
};
