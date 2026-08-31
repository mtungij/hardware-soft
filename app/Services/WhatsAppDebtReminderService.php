<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Support\AuthorizationScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WhatsAppDebtReminderService
{
    public function __construct(private WhatsAppLocalization $localization) {}

    public function canReceiveManagementSummary(?User $user): bool
    {
        return $user?->hasAnyPermission(['dashboard.receivables', 'reports.receivables', 'accounting.view', 'customer-balances.view']) ?? false;
    }

    /** @return Collection<int, Sale> */
    public function managementDebts(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date): Collection
    {
        if (! $this->canReceiveManagementSummary($recipient->user)) {
            return collect();
        }

        $query = $this->authoritativeReceivables($company)
            ->with(['customer:id,company_id,branch_id,name,phone', 'branch:id,company_id,name'])
            ->whereDate('expected_payment_date', '<=', $date->copy()->addDay()->toDateString());

        AuthorizationScope::sales($query, $recipient->user);
        AuthorizationScope::reports($query, $recipient->user);

        return $query->orderBy('expected_payment_date')->orderByDesc('balance_amount')->get();
    }

    public function managementMessage(Company $company, Collection $debts, CarbonInterface $date): string
    {
        $tomorrow = $debts->filter(fn (Sale $sale): bool => $sale->expected_payment_date?->isSameDay($date->copy()->addDay()));
        $today = $debts->filter(fn (Sale $sale): bool => $sale->expected_payment_date?->isSameDay($date));
        $overdue = $debts->filter(fn (Sale $sale): bool => $sale->expected_payment_date?->lt($date->copy()->startOfDay()));
        $top = $debts->groupBy(fn (Sale $sale): string => (string) ($sale->customer_id ?: 'sale-'.$sale->id))
            ->map(fn (Collection $rows): array => [
                'name' => $rows->first()->customer?->name ?: $rows->first()->temporary_customer_name ?: $this->localization->get($company, 'common.customer'),
                'amount' => (float) $rows->sum('balance_amount'),
            ])->sortByDesc('amount')->take(5)->values();

        $lines = [$this->localization->get($company, 'debt.management_title'), $this->localization->date($company, $date), ''];
        foreach ([['due_tomorrow', $tomorrow], ['due_today', $today], ['overdue', $overdue]] as [$key, $rows]) {
            $label = $this->localization->get($company, 'debt.'.$key);
            $lines[] = $label;
            $lines[] = $this->localization->get($company, 'debt.customers').': '.$rows->map(fn (Sale $sale): string => (string) ($sale->customer_id ?: 'sale-'.$sale->id))->unique()->count();
            $lines[] = $this->localization->get($company, 'debt.total').': TZS '.$this->money($rows->sum('balance_amount'));
            $lines[] = '';
        }
        $lines[] = $this->localization->get($company, 'debt.highest_outstanding').':';
        foreach ($top as $index => $row) {
            $lines[] = ($index + 1).'. '.$row['name'].' — TZS '.$this->money($row['amount']);
        }
        $lines[] = '';
        $lines[] = $this->localization->get($company, 'debt.open_report');

        return implode("\n", $lines);
    }

    public function customerMessage(Company $company, Sale $sale, string $kind, CarbonInterface $now): string
    {
        $customer = $sale->customer;
        $name = $customer?->name ?: $sale->temporary_customer_name ?: $this->localization->get($company, 'common.customer');
        $due = $this->localization->date($company, $sale->expected_payment_date);
        $reference = $sale->sale_number;
        $amount = $this->money($sale->balance_amount);

        if ($kind === 'overdue') {
            $days = max(1, $sale->expected_payment_date?->startOfDay()->diffInDays($now->copy()->startOfDay()) ?? 1);

            return implode("\n\n", [
                $this->localization->get($company, 'debt.greeting', ['name' => $name]),
                $this->localization->get($company, 'debt.reminder_from', ['company' => $company->company_name]),
                $this->localization->get($company, 'debt.overdue_body', ['amount' => $amount, 'date' => $due, 'days' => $days]),
                $this->localization->get($company, 'debt.reference').': '.$reference,
                $this->localization->get($company, 'debt.contact'),
                $this->localization->get($company, 'debt.thanks'),
            ]);
        }

        return implode("\n\n", [
            $this->localization->get($company, 'debt.greeting', ['name' => $name]),
            $this->localization->get($company, 'debt.reminder_from', ['company' => $company->company_name]),
            $this->localization->get($company, 'debt.due_body', ['amount' => $amount, 'date' => $due]),
            $this->localization->get($company, 'debt.reference').': '.$reference,
            $this->localization->get($company, 'debt.paid_contact'),
            $this->localization->get($company, 'debt.thanks'),
        ]);
    }

    public function kind(Sale $sale, CarbonInterface $date): ?string
    {
        if (! $sale->expected_payment_date) {
            return null;
        }
        if ($sale->expected_payment_date->isSameDay($date->copy()->addDay())) {
            return 'due_tomorrow';
        }
        if ($sale->expected_payment_date->isSameDay($date)) {
            return 'due_today';
        }
        if ($sale->expected_payment_date->lt($date->copy()->startOfDay())) {
            return 'overdue';
        }

        return null;
    }

    public function enabledForKind(CompanyWhatsAppSetting $setting, string $kind): bool
    {
        return match ($kind) {
            'due_tomorrow' => $setting->debt_due_tomorrow_enabled,
            'due_today' => $setting->debt_due_today_enabled,
            'overdue' => $setting->debt_overdue_enabled,
            default => false,
        };
    }

    public function enabledRows(Collection $debts, CompanyWhatsAppSetting $setting, CarbonInterface $date): Collection
    {
        return $debts->filter(function (Sale $sale) use ($setting, $date): bool {
            $kind = $this->kind($sale, $date);

            return $kind !== null && $this->enabledForKind($setting, $kind);
        })->values();
    }

    public function overdueCycle(Sale $sale, CarbonInterface $date, int $intervalDays): int
    {
        $days = max(1, $sale->expected_payment_date->copy()->startOfDay()->diffInDays($date->copy()->startOfDay()));

        return intdiv($days - 1, max(1, $intervalDays));
    }

    public function revalidateCustomerNotification(WhatsAppNotification $notification, CompanyWhatsAppSetting $setting): ?string
    {
        $saleId = (int) data_get($notification->metadata, 'receivable_id');
        $customerId = (int) data_get($notification->metadata, 'customer_id');
        $originalKind = (string) data_get($notification->metadata, 'debt_kind');
        $sale = Sale::withoutGlobalScopes()->with('customer')
            ->where('company_id', $notification->company_id)
            ->where('customer_id', $customerId)
            ->whereKey($saleId)
            ->where('status', 'completed')
            ->where('balance_amount', '>', 0)
            ->first();

        if (! $sale || ! $sale->customer || $sale->customer->status !== 'active' || ! $sale->customer->whatsapp_debt_reminders_enabled) {
            return null;
        }

        $now = CarbonImmutable::now($setting->timezone);
        if ($this->kind($sale, $now) !== $originalKind || ! $this->enabledForKind($setting, $originalKind)) {
            return null;
        }

        return $this->customerMessage($setting->company, $sale, $originalKind, $now);
    }

    public function authoritativeReceivables(Company $company): Builder
    {
        return Sale::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'completed')
            ->where('balance_amount', '>', 0)
            ->whereNotNull('expected_payment_date');
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0);
    }
}
