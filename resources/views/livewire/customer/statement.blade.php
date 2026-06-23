<?php

use App\Models\CustomerDeposit;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\AccountingService;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;

layout('layouts.customer');

$customer = computed(fn () => auth('customer')->user()->customer);
$sales = computed(fn () => Sale::where('customer_id', $this->customer->id)->where('status', 'completed')->latest('sale_date')->get());
$payments = computed(fn () => CustomerPayment::where('customer_id', $this->customer->id)->latest('payment_date')->get());
$deposits = computed(fn () => CustomerDeposit::where('customer_id', $this->customer->id)->latest()->get());
$balance = computed(fn () => app(AccountingService::class)->customerBalance($this->customer));

?>

<div>
    <x-page-header title="Customer Statement" :description="$this->customer->name" :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Statement' => null]">
        <button onclick="window.print()" class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">Print Statement</button>
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-card><p class="text-sm text-slate-500">Opening Balance</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $this->customer->opening_balance, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Outstanding Balance</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ number_format((float) $this->balance, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Approved Deposits</p><p class="mt-2 text-xl font-black text-emerald-600">TZS {{ number_format((float) $this->deposits->whereIn('status', ['approved', 'partial'])->sum('balance_amount'), 2) }}</p></x-card>
    </div>
    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-card title="Sales">
            <x-table :headers="['Date', 'Sale', 'Total', 'Paid', 'Balance']">
                @forelse ($this->sales as $sale)
                    <tr><td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->total_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->paid_amount, 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No sales found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
        <x-card title="Payments">
            <x-table :headers="['Date', 'Method', 'Reference', 'Amount']">
                @forelse ($this->payments as $payment)
                    <tr><td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $payment->amount, 2) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No payments found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
