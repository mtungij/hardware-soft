<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppDebtPdfService;
use App\Services\WhatsAppDebtReminderService;
use App\Services\WhatsAppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QueueWhatsAppDebtReminders extends Command
{
    protected $signature = 'whatsapp:debt-reminders {--company=} {--force}';

    protected $description = 'Queue grouped management debt summaries and customer due/overdue reminders';

    public function handle(
        WhatsAppNotificationService $notifications,
        WhatsAppDebtReminderService $debts,
        WhatsAppDebtPdfService $pdfs,
    ): int {
        CompanyWhatsAppSetting::withoutGlobalScopes()
            ->where('enabled', true)
            ->where('sending_paused', false)
            ->where('debt_reminders_enabled', true)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->get()->each(function (CompanyWhatsAppSetting $setting) use ($notifications, $debts, $pdfs): void {
                if (! $setting->categoryEnabled('customer_debt')) {
                    return;
                }

                $now = CarbonImmutable::now($setting->timezone);
                if (! $this->option('force') && $now->format('H:i') !== substr($setting->debt_reminder_time, 0, 5)) {
                    return;
                }

                $company = Company::query()->find($setting->company_id);
                if (! $company) {
                    return;
                }
                $setting->setRelation('company', $company);

                $this->queueManagementSummaries($company, $setting, $now, $notifications, $debts, $pdfs);
                $this->queueCustomerReminders($company, $setting, $now, $notifications, $debts);
            });

        return self::SUCCESS;
    }

    private function queueManagementSummaries(
        Company $company,
        CompanyWhatsAppSetting $setting,
        CarbonImmutable $now,
        WhatsAppNotificationService $notifications,
        WhatsAppDebtReminderService $debts,
        WhatsAppDebtPdfService $pdfs,
    ): void {
        WhatsAppRecipient::withoutGlobalScopes()->with(['user.roles', 'branch'])
            ->where('company_id', $company->id)->where('active', true)->get()
            ->filter(fn (WhatsAppRecipient $recipient): bool => $recipient->accepts('customer_debt', null)
                && $debts->canReceiveManagementSummary($recipient->user))
            ->each(function (WhatsAppRecipient $recipient) use ($company, $setting, $now, $notifications, $debts, $pdfs): void {
                $rows = $debts->enabledRows($debts->managementDebts($company, $recipient, $now), $setting, $now);
                if ($rows->isEmpty()) {
                    return;
                }

                $attachment = $setting->attach_debt_summary_pdf ? $pdfs->generate($company, $recipient, $now, $rows) : null;
                $notifications->queueRecipient(
                    $company, $setting, $recipient, 'customer_debt', 'management_debt_summary',
                    'management-debt-summary:'.$now->toDateString(),
                    $debts->managementMessage($company, $rows, $now),
                    $recipient->scope === 'branch' ? (int) $recipient->branch_id : null,
                    $attachment, $attachment ? 'file' : null,
                    ['summary_date' => $now->toDateString(), 'receivable_ids' => $rows->pluck('id')->all(), 'scope' => $recipient->scope],
                );
            });
    }

    private function queueCustomerReminders(
        Company $company,
        CompanyWhatsAppSetting $setting,
        CarbonImmutable $now,
        WhatsAppNotificationService $notifications,
        WhatsAppDebtReminderService $debts,
    ): void {
        $debts->authoritativeReceivables($company)
            ->with('customer')
            ->whereDate('expected_payment_date', '<=', $now->addDay()->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($sales) use ($company, $setting, $now, $notifications, $debts): void {
                foreach ($sales as $sale) {
                    $customer = $sale->customer;
                    $kind = $debts->kind($sale, $now);
                    if (! $customer || $customer->status !== 'active' || ! $customer->whatsapp_debt_reminders_enabled || blank($customer->phone)
                        || ! $kind || ! $debts->enabledForKind($setting, $kind)) {
                        continue;
                    }

                    $cycle = $kind === 'overdue'
                        ? ':cycle-'.$debts->overdueCycle($sale, $now, $setting->debt_overdue_interval_days)
                        : ':'.$now->toDateString();
                    $notifications->queuePhone(
                        $company, $setting, $customer->phone, 'customer_debt', 'debt_'.$kind,
                        'customer-debt:'.$customer->id.':'.$sale->id.':'.$kind.$cycle,
                        $debts->customerMessage($company, $sale, $kind, $now),
                        (int) $sale->branch_id,
                        ['receivable_id' => $sale->id, 'customer_id' => $customer->id, 'debt_kind' => $kind, 'due_date' => $sale->expected_payment_date->toDateString()],
                    );
                }
            });
    }
}
