<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    public static function context(): array
    {
        $customer = auth('customer')->user() ?: (request()->user() instanceof CustomerAccount ? request()->user() : null);
        $portal = request()->is('customer', 'customer/*', 'api/customer/*') || auth()->getDefaultDriver() === 'customer';
        $actor = $portal ? $customer : auth('web')->user();

        return [(int) $actor?->company_id, $portal ? $customer?->customer?->branch_id : $actor?->branch_id];
    }

    public static function available(int $companyId, ?int $branchId = null, bool $history = false): Builder
    {
        $portal = request()->is('customer', 'customer/*', 'api/customer/*') || auth()->getDefaultDriver() === 'customer';
        $customer = $portal
            ? (auth('customer')->user() ?: (request()->user() instanceof CustomerAccount ? request()->user() : null))
            : null;
        $actor = $portal ? null : auth('web')->user();

        $allowed = true;

        if ($portal) {
            $allowed = $customer instanceof CustomerAccount
                && (int) $customer->company_id === $companyId;
        }

        if ($actor instanceof User) {
            if ((int) $actor->company_id !== $companyId) {
                $allowed = false;
            } elseif (BranchAccess::restricted($actor)) {
                $branchId ??= (int) $actor->branch_id;
                $allowed = BranchAccess::canAccessBranch($actor, $branchId);
            } elseif ($branchId !== null) {
                $allowed = BranchAccess::canAccessBranch($actor, $branchId);
            }
        }

        return PaymentMethod::withoutGlobalScopes()->where('company_id', $companyId)->when(! $allowed, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(! $history, fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderByDesc('is_default')->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')->orderBy('sort_order')->orderBy('id');
    }

    public static function defaultValue(?int $companyId = null, ?int $branchId = null): string
    {
        [$contextCompany, $contextBranch] = self::context();
        $method = self::available($companyId ?? $contextCompany, $branchId ?? $contextBranch)->first();

        return $method ? 'method:'.$method->id : '';
    }

    public static function resolve(mixed $value, int $companyId, ?int $branchId = null, bool $allowInactive = false): PaymentMethod
    {
        if (! $branchId && auth('web')->user()?->company_id === $companyId && ! BranchAccess::restricted(auth('web')->user()) && is_string($value) && preg_match('/^method:([1-9][0-9]*)$/D', $value, $candidate)) {
            $branchId = PaymentMethod::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($candidate[1])->value('branch_id');
        }
        $query = self::available($companyId, $branchId, $allowInactive);
        if (is_string($value) && preg_match('/^method:([1-9][0-9]*)$/D', $value, $match)) {
            $query->whereKey($match[1]);
        } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $query->whereKey($value);
        } else {
            $query->where('legacy_code', $value === 'cash_deposit' ? 'cash' : (is_string($value) ? $value : ''));
        }
        $method = $query->first();
        if (! $method || ($branchId && ! Branch::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($branchId)->exists())) {
            throw ValidationException::withMessages(['payment_method' => __('payments.unavailable')]);
        }

        return $method;
    }

    public static function filter(Builder|Collection $query, string $value): Builder|Collection
    {
        return preg_match('/^method:([1-9][0-9]*)$/D', $value, $match)
            ? $query->where('payment_method_id', $match[1]) : $query->where('payment_method', $value);
    }

    public static function seedDefaults(int $companyId): void
    {
        foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'] as $type => $label) {
            DB::table('payment_methods')->insertOrIgnore([
                'company_id' => $companyId, 'type' => $type, 'display_name' => $label, 'legacy_code' => $type,
                'is_active' => true, 'is_default' => $type === 'cash', 'show_on_quotation' => false,
                'show_on_proforma' => false, 'show_on_invoice' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function save($actor, ?int $id, array $data): PaymentMethod
    {
        abort_unless($actor?->company_id && $actor->can('payment_methods.manage'), 403);

        return DB::transaction(function () use ($actor, $id, $data) {
            Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            if (! empty($data['branch_id'])) {
                abort_unless(BranchAccess::canAccessBranch($actor, (int) $data['branch_id']), 403);
            }
            $method = $id ? PaymentMethod::withoutGlobalScopes()->where('company_id', $actor->company_id)->findOrFail($id) : new PaymentMethod;
            if (! empty($data['is_default']) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_default' => __('payments.default_active')]);
            }
            if (! empty($data['is_default'])) {
                PaymentMethod::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('branch_id', $data['branch_id'] ?: null)->update(['is_default' => false]);
            }
            $method->fill(array_merge($data, ['company_id' => $actor->company_id]))->save();

            return $method;
        });
    }
}
