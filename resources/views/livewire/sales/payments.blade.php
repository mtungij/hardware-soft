<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Support\AuthorizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'sale' => null,
    'payment_method' => 'cash',
    'amount' => '',
    'reference_number' => '',
]);

mount(function (Sale $sale) {
    AuthorizationScope::authorizeSale(auth()->user(), $sale);
    $this->sale = $sale->load(['customer', 'payments']);
    $this->amount = (string) $sale->balance_amount;
});

$receivePayment = function () {
    abort_unless(auth()->user()->can('sales.edit') || auth()->user()->can('receive sale payments'), 403);
    $this->validate([
        'payment_method' => ['required', 'in:cash,mobile_money,bank'],
        'amount' => ['required', 'numeric', 'gt:0'],
        'reference_number' => ['nullable', 'string', 'max:255'],
    ]);

    DB::transaction(function () {
        $sale = Sale::query()->whereKey($this->sale->id)->lockForUpdate()->firstOrFail();

        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => __('application.cancelled_sales_cannot_receive_payments_946394cb')]);
        }

        $amount = min((float) $this->amount, (float) $sale->balance_amount);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('application.this_sale_has_no_outstanding_balance_edb01eed')]);
        }

        $sale->payments()->create([
            'payment_method' => $this->payment_method,
            'amount' => $amount,
            'reference_number' => $this->reference_number ?: null,
            'received_by' => auth()->id(),
            'payment_date' => now()->toDateString(),
        ]);

        $newPaid = (float) $sale->paid_amount + $amount;
        $newBalance = max(0, (float) $sale->total_amount - $newPaid);

        $sale->update([
            'paid_amount' => min($newPaid, (float) $sale->total_amount),
            'balance_amount' => $newBalance,
            'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
        ]);

        if ($sale->customer_id) {
            $customer = Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->first();
            $customer?->decrement('balance_amount', min($amount, (float) $customer->balance_amount));
        }

        $this->sale = $sale->refresh()->load(['customer', 'payments']);
        $this->amount = (string) $this->sale->balance_amount;
        $this->reference_number = '';
    });

    session()->flash('success', __('application.payment_received_successfully_e4e05f98'));
};

?>

<div>
    <x-page-header title="{{ __('application.receive_sale_payment_66e27a07') }}" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => route('sales.show', $sale), 'Payment' => null]" />

    <div class="grid gap-6 lg:grid-cols-[1fr_420px]">
        <x-card title="{{ __('application.payment_history_cfeba031') }}">
            <div class="mb-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.total_b25928c6') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.paid_dc9d4584') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</p></div>
                <div class="rounded-lg bg-navy-900 p-4 text-white"><p class="text-xs text-slate-300">{{ __('application.balance_90eef613') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</p></div>
            </div>
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('application.date_eb9a4bc1') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('application.method_88306943') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('application.reference_db1c7845') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('application.amount_43dc8532') }}</th>
                    </tr>
                </x-slot:head>
                @foreach ($sale->payments as $payment)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                        <td class="px-4 py-3">{{ \App\Support\UiText::label($payment->payment_method) }}</td>
                        <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                        <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>

        <x-card title="{{ __('application.record_payment_6577ced3') }}">
            @if ((float) $sale->balance_amount <= 0)
                <div class="rounded-lg bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('application.this_sale_is_fully_paid_69db49bd') }}</div>
            @else
                <form wire:submit="receivePayment" class="space-y-4">
                    <label class="block text-sm font-bold">{{ __('application.payment_method_f383f6a2') }}</label>
                    <select wire:model="payment_method" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">{{ __('application.cash_758ec54e') }}</option>
                        <option value="mobile_money">{{ __('application.mobile_money_6a38d1e4') }}</option>
                        <option value="bank">{{ __('application.bank_9e89988c') }}</option>
                    </select>
                    <x-money-input label="{{ __('application.amount_43dc8532') }}" name="amount" wire:model="amount" required />
                    <x-form-input label="{{ __('application.reference_number_74195db5') }}" wire:model="reference_number" />
                    <button class="w-full rounded-lg bg-build-orange px-4 py-3 text-sm font-black text-white">{{ __('application.receive_payment_09fd7056') }}</button>
                </form>
            @endif
        </x-card>
    </div>
</div>
