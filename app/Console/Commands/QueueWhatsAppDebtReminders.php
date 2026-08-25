<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Sale;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppNotificationService;
use App\Support\AuthorizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QueueWhatsAppDebtReminders extends Command
{
    protected $signature = 'whatsapp:debt-reminders {--company=} {--force}';

    protected $description = 'Queue grouped due and overdue customer debt reminders for management recipients';

    public function handle(WhatsAppNotificationService $notifications): int
    {
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('enabled', true)->where('sending_paused', false)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->get()->each(function (CompanyWhatsAppSetting $setting) use ($notifications): void {
                $now = CarbonImmutable::now($setting->timezone);
                if (! $this->option('force') && $now->format('H') !== '08') {
                    return;
                }
                $company = Company::query()->find($setting->company_id);
                if (! $company) {
                    return;
                }

                WhatsAppRecipient::withoutGlobalScopes()->with(['user.roles', 'branch'])
                    ->where('company_id', $company->id)->where('active', true)->get()
                    ->filter(fn (WhatsAppRecipient $recipient): bool => $recipient->accepts('customer_debt', null))
                    ->each(function (WhatsAppRecipient $recipient) use ($notifications, $setting, $company, $now): void {
                        $query = Sale::withoutGlobalScopes()->with('customer')->where('company_id', $company->id)
                            ->where('status', 'completed')->where('balance_amount', '>', 0)
                            ->whereNotNull('expected_payment_date')->whereDate('expected_payment_date', '<=', $now->addDay());
                        if ($recipient->user) {
                            AuthorizationScope::sales($query, $recipient->user);
                        } elseif ($recipient->scope === 'branch') {
                            $query->where('branch_id', $recipient->branch_id);
                        }
                        $debts = $query->orderBy('expected_payment_date')->limit(50)->get();
                        if ($debts->isEmpty()) {
                            return;
                        }

                        $canSeeDetails = $recipient->user?->hasAnyPermission(['reports.receivables', 'accounting.view', 'customer-balances.view']) ?? false;
                        $lines = ['HARDEX CUSTOMER DEBT ALERT', $debts->count().' debts are due or overdue.'];
                        if ($canSeeDetails) {
                            foreach ($debts as $index => $sale) {
                                $status = $sale->expected_payment_date->isPast() && ! $sale->expected_payment_date->isToday() ? 'OVERDUE' : ($sale->expected_payment_date->isToday() ? 'DUE TODAY' : 'DUE TOMORROW');
                                $lines[] = ($index + 1).'. '.($sale->customer?->name ?: $sale->temporary_customer_name ?: $sale->sale_number).' — TZS '.number_format((float) $sale->balance_amount, 0).' ('.$status.')';
                            }
                        } else {
                            $lines[] = 'Open HARDEX with an authorized account to view customer and balance details.';
                        }

                        $state = $debts->map(fn (Sale $sale) => [$sale->id, (string) $sale->balance_amount, optional($sale->expected_payment_date)->toDateString()])->toJson();
                        $notifications->queueRecipient(
                            $company, $setting, $recipient, 'customer_debt', 'customer_debt_reminder',
                            'customer-debt:'.$now->toDateString().':'.hash('sha256', $state), implode("\n", $lines),
                            $recipient->scope === 'branch' ? (int) $recipient->branch_id : null,
                        );
                    });
            });

        return self::SUCCESS;
    }
}
