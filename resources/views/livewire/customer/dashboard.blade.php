<?php

use App\Models\CustomerDeposit;
use App\Models\CustomerReceipt;
use App\Models\Sale;
use App\Services\AccountingService;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;

layout('layouts.customer');

$account = computed(fn () => auth('customer')->user()->load('customer'));
$customer = computed(fn () => $this->account->customer);
$outstanding = computed(fn () => app(AccountingService::class)->customerBalance($this->customer));
$depositBalance = computed(fn () => CustomerDeposit::where('customer_id', $this->customer->id)->whereIn('status', ['approved', 'partial'])->sum('balance_amount'));
$pendingReceipts = computed(fn () => CustomerReceipt::where('customer_id', $this->customer->id)->where('status', 'pending')->count());
$recentSales = computed(fn () => Sale::where('customer_id', $this->customer->id)->latest()->limit(5)->get());

?>

<div>
    <x-page-header title="Customer Dashboard" :description="'Welcome back, '.$this->account->name" :breadcrumbs="['Customer Portal' => null]" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-card><p class="text-sm font-semibold text-slate-500">Outstanding Debt</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ number_format($this->outstanding, 2) }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">Deposit Balance</p><p class="mt-2 text-2xl font-black text-emerald-600">TZS {{ number_format((float) $this->depositBalance, 2) }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">Pending Receipts</p><p class="mt-2 text-2xl font-black text-amber-600">{{ $this->pendingReceipts }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">Credit Limit</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) $this->customer->credit_limit, 2) }}</p></x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-card title="Quick Actions" class="lg:col-span-1">
            <div class="space-y-3">
                <a href="{{ route('customer.receipts.create') }}" wire:navigate class="block rounded-xl bg-build-orange px-4 py-3 text-center text-sm font-black text-white">Upload Payment Receipt</a>
                <a href="{{ route('customer.deposits.create') }}" wire:navigate class="block rounded-xl border border-slate-200 px-4 py-3 text-center text-sm font-black dark:border-slate-700">Upload Deposit</a>
                <a href="{{ route('customer.statement') }}" wire:navigate class="block rounded-xl border border-slate-200 px-4 py-3 text-center text-sm font-black dark:border-slate-700">View Statement</a>
            </div>
        </x-card>

        <x-card title="Recent Sales" class="lg:col-span-2">
            <x-table :headers="['Date', 'Sale', 'Total', 'Paid', 'Balance', 'Status']">
                @forelse ($this->recentSales as $sale)
                    <tr>
                        <td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td>
                        <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->paid_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $sale->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($sale->payment_status === 'partial' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200') }}">{{ str($sale->payment_status)->title() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No sales found for your account.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
