<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppDailyPdfService;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QueueWhatsAppDailySummaries extends Command
{
    protected $signature = 'whatsapp:daily-summary {--company=} {--date=} {--force}';

    protected $description = 'Queue company daily management summaries for configured recipients';

    public function handle(WhatsAppNotificationService $notifications, WhatsAppMessageFactory $messages, WhatsAppDailyPdfService $pdfs): int
    {
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('enabled', true)->where('sending_paused', false)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->get()->each(function (CompanyWhatsAppSetting $setting) use ($notifications, $messages, $pdfs): void {
                $now = CarbonImmutable::now($setting->timezone);
                if (! $this->option('force') && ! $this->option('date') && $now->format('H:i') !== substr($setting->daily_summary_time, 0, 5)) {
                    return;
                }

                $date = $this->option('date') ? CarbonImmutable::parse($this->option('date'), $setting->timezone) : $now;
                $company = Company::query()->find($setting->company_id);
                if (! $company) {
                    return;
                }

                WhatsAppRecipient::withoutGlobalScopes()->with(['user.roles', 'branch'])
                    ->where('company_id', $company->id)->where('active', true)->get()
                    ->filter(fn (WhatsAppRecipient $recipient): bool => $recipient->accepts('daily_summary', null))
                    ->each(function (WhatsAppRecipient $recipient) use ($notifications, $messages, $pdfs, $company, $setting, $date): void {
                        $summary = $messages->dailySummary($company, $recipient, $date);
                        $attachment = $setting->attach_daily_summary_pdf ? $pdfs->generate($company, $recipient, $date, $summary) : null;
                        $notifications->queueRecipient(
                            $company, $setting, $recipient, 'daily_summary', 'daily_management_summary',
                            'daily-summary:'.$date->toDateString(), $summary, $recipient->scope === 'branch' ? (int) $recipient->branch_id : null,
                            $attachment, $attachment ? 'file' : null, ['summary_date' => $date->toDateString()],
                        );
                    });
            });

        return self::SUCCESS;
    }
}
