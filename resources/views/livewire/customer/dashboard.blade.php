<?php

use App\Models\CustomerDeposit;
use App\Models\CustomerNotification;
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
$importantAnnouncement = computed(fn () => CustomerNotification::query()
    ->where('customer_id', $this->customer->id)
    ->where('type', 'announcement')
    ->whereIn('priority', ['high', 'urgent'])
    ->whereNull('read_at')
    ->latest()
    ->first());
$paymentStatusLabel = fn (string $status) => [
    'paid' => __('messages.status.paid'),
    'partial' => __('messages.status.partial'),
    'unpaid' => __('messages.status.unpaid'),
][$status] ?? str($status)->replace('_', ' ')->title();

?>

@php
    $company = \App\Models\Company::current();
    $companyName = $company?->company_name ?: 'Customer Portal';
@endphp

<div data-tour="customer-dashboard">
    <x-page-header :title="__('messages.dashboard.title')" :description="__('messages.welcome_back', ['name' => $this->account->name])" :breadcrumbs="[__('messages.customer_portal') => null]" />

    <x-card class="mb-4">
        <h2 class="text-xl font-black text-navy-900 dark:text-white">Karibu kwenye {{ $companyName }}</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('messages.welcome_message') }}</p>
    </x-card>

    <x-help-tip class="mb-4">
        Pakia risiti za malipo ili deni lako lipungue baada ya uhakiki wa staff.
    </x-help-tip>

    <div class="mb-4">
        <x-onboarding-checklist context="customer" />
    </div>

    @if ($this->importantAnnouncement)
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-black">{{ __('messages.notifications.important_notice') }}: {{ $this->importantAnnouncement->title }}</p>
                    <p class="mt-1 text-sm">{{ $this->importantAnnouncement->message }}</p>
                </div>
                <a href="{{ route('customer.notifications.index') }}" wire:navigate class="rounded-lg bg-build-orange px-3 py-2 text-center text-sm font-bold text-white">{{ __('messages.notifications.read_more') }}</a>
            </div>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-card><p class="text-sm font-semibold text-slate-500">{{ __('messages.dashboard.outstanding_debt') }}</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ number_format($this->outstanding, 2) }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">{{ __('messages.dashboard.approved_deposits') }}</p><p class="mt-2 text-2xl font-black text-emerald-600">TZS {{ number_format((float) $this->depositBalance, 2) }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">{{ __('messages.dashboard.pending_receipts') }}</p><p class="mt-2 text-2xl font-black text-amber-600">{{ $this->pendingReceipts }}</p></x-card>
        <x-card><p class="text-sm font-semibold text-slate-500">{{ __('messages.dashboard.credit_limit') }}</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) $this->customer->credit_limit, 2) }}</p></x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-card :title="__('messages.dashboard.quick_actions')" class="lg:col-span-1">
            <div class="space-y-3">
                <a href="{{ route('customer.receipts.create') }}" wire:navigate class="block rounded-xl bg-build-orange px-4 py-3 text-center text-sm font-black text-white">{{ __('messages.receipts.upload') }}</a>
                <a href="{{ route('customer.deposits.create') }}" wire:navigate class="block rounded-xl border border-slate-200 px-4 py-3 text-center text-sm font-black dark:border-slate-700">{{ __('messages.deposits.upload') }}</a>
                <a href="{{ route('customer.statement') }}" wire:navigate class="block rounded-xl border border-slate-200 px-4 py-3 text-center text-sm font-black dark:border-slate-700">{{ __('messages.nav.statements') }}</a>
            </div>
        </x-card>

        <x-card :title="__('messages.dashboard.recent_purchases')" class="lg:col-span-2">
            <x-table :headers="[__('messages.table.date'), __('messages.debts.invoice_number'), __('messages.table.total'), __('messages.table.paid'), __('messages.table.balance'), __('messages.table.status')]">
                @forelse ($this->recentSales as $sale)
                    <tr>
                        <td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td>
                        <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->paid_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $sale->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($sale->payment_status === 'partial' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200') }}">{{ $this->paymentStatusLabel($sale->payment_status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('messages.dashboard.no_sales') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
