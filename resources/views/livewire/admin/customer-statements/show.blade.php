<?php

use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\AccountingService;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['customer' => null]);

mount(function (Customer $customer) {
    $this->customer = $customer;
});

$sales = computed(fn () => Sale::where('customer_id', $this->customer->id)->where('status', 'completed')->latest('sale_date')->get());
$payments = computed(fn () => CustomerPayment::where('customer_id', $this->customer->id)->latest('payment_date')->get());
$deposits = computed(fn () => CustomerDeposit::where('customer_id', $this->customer->id)->latest()->get());
$balance = computed(fn () => app(AccountingService::class)->customerBalance($this->customer));

?>

<div>
    <x-page-header title="{{ __('application.customer_statement_003b2523') }}" :description="$customer->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), 'Statement' => null]">
        <button onclick="window.print()" class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">{{ __('application.print_5b221e9c') }}</button>
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-4">
        <x-card><p class="text-sm text-slate-500">{{ __('application.opening_balance_1a1a6305') }}</p><p class="mt-2 text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($customer->opening_balance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.outstanding_f8ee57ec') }}</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($this->balance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.deposit_balance_a88f9492') }}</p><p class="mt-2 text-xl font-black text-emerald-600">TZS {{ \App\Support\NumberFormatter::money($this->deposits->whereIn('status', ['approved', 'partial'])->sum('balance_amount')) }}</p></x-card>
    </div>
    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-card title="{{ __('application.credit_sales_e10abc1a') }}">
            <x-table :headers="[__('application.date_eb9a4bc1'), __('application.sale_0028d743'), __('application.total_b25928c6'), __('application.paid_dc9d4584'), __('application.balance_90eef613')]">
                @foreach ($this->sales as $sale)
                    <tr><td class="px-4 py-3">{{ $sale->sale_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
        <x-card title="{{ __('application.payments_44357ae5') }}">
            <x-table :headers="[__('application.date_eb9a4bc1'), __('application.method_88306943'), __('application.reference_db1c7845'), __('application.amount_43dc8532')]">
                @foreach ($this->payments as $payment)
                    <tr><td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3">{{ \App\Support\UiText::label($payment->payment_method) }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
</div>
