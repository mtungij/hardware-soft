<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'enabled', 'sending_paused', 'device_id', 'timezone', 'whatsapp_notification_language', 'daily_summary_time', 'attach_daily_summary_pdf', 'debt_reminders_enabled', 'debt_due_tomorrow_enabled', 'debt_due_today_enabled', 'debt_overdue_enabled', 'debt_reminder_time', 'debt_overdue_interval_days', 'attach_debt_summary_pdf', 'quiet_hours_start', 'quiet_hours_end', 'enabled_categories', 'minimum_send_interval_seconds', 'maximum_messages_per_minute', 'maximum_messages_per_hour', 'low_stock_cooldown_hours', 'attach_stock_alert_pdf', 'test_recipient', 'last_device_state', 'last_checked_at'])]
class CompanyWhatsAppSetting extends Model
{
    use HasCompany;

    protected $table = 'company_whatsapp_settings';

    public const DEFAULT_CATEGORIES = [
        'daily_summary', 'stock_alerts', 'sales', 'security', 'customer_payments',
        'customer_debt', 'purchases', 'customer_materials', 'production',
        'customer_requests', 'quotations', 'quotation_acceptance', 'customer_invoices',
        'customer_portal',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ready(): bool
    {
        return $this->enabled && ! $this->sending_paused && filled($this->device_id) && $this->last_device_state === 'logged_in';
    }

    public function categoryEnabled(string $category): bool
    {
        return in_array($category, $this->enabled_categories ?: self::DEFAULT_CATEGORIES, true);
    }

    public function notificationLanguage(): string
    {
        return in_array($this->whatsapp_notification_language, ['en', 'sw'], true)
            ? $this->whatsapp_notification_language
            : 'en';
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sending_paused' => 'boolean',
            'attach_daily_summary_pdf' => 'boolean',
            'debt_reminders_enabled' => 'boolean',
            'debt_due_tomorrow_enabled' => 'boolean',
            'debt_due_today_enabled' => 'boolean',
            'debt_overdue_enabled' => 'boolean',
            'debt_overdue_interval_days' => 'integer',
            'attach_debt_summary_pdf' => 'boolean',
            'attach_stock_alert_pdf' => 'boolean',
            'enabled_categories' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
