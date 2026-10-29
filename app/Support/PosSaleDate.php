<?php

namespace App\Support;

use App\Models\CashbookSession;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class PosSaleDate
{
    public function timezone(int $companyId): string
    {
        $timezone = Company::findOrFail($companyId)->timezone ?: config('app.timezone', 'UTC');
        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return config('app.timezone', 'UTC');
        }
    }

    public function today(int $companyId): string
    {
        return CarbonImmutable::now($this->timezone($companyId))->toDateString();
    }

    public function canBackdate(User $user): bool
    {
        return $user->can('sales.backdate');
    }

    public function validate(User $user, int $branchId, mixed $value = null, bool $confirmed = false): string
    {
        if (! BranchAccess::canAccessBranch($user, $branchId)) {
            $this->reject('branch');
        }
        $today = CarbonImmutable::now($this->timezone($user->company_id))->startOfDay();
        $value ??= $today->toDateString();
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $this->reject('invalid');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $today->timezone);
        } catch (\Throwable) {
            $this->reject('invalid');
        }
        if (! $date || $date->toDateString() !== $value) {
            $this->reject('invalid');
        }
        if ($date->greaterThan($today)) {
            $this->reject('future');
        }
        if ($date->lessThan($today)) {
            if (! $this->canBackdate($user)) {
                $this->reject('unauthorized');
            }
            $limit = max(0, (int) config('pos.max_backdate_days', 30));
            if ($date->lessThan($today->subDays($limit))) {
                throw ValidationException::withMessages(['sale_date' => __('sale_dates.limit', ['days' => $limit])]);
            }
            if (! $confirmed) {
                throw ValidationException::withMessages(['backdate_confirmed' => __('sale_dates.confirm_required')]);
            }
        }
        // Cashbook closure is the existing accounting control; do not invent a new ledger.
        // Lock the relevant sessions in the same transaction as sale/stock recording.
        $closed = CashbookSession::withoutGlobalScopes()->where('company_id', $user->company_id)
            ->where('branch_id', $branchId)->where(fn ($q) => $q->whereDate('session_date', $value)->orWhereDate('session_date', $today->toDateString()))
            ->lockForUpdate()->get()->contains('status', 'closed');
        if ($closed) {
            $this->reject('closed');
        }

        return $value;
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['sale_date' => __('sale_dates.'.$reason)]);
    }
}
