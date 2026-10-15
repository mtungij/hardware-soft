<?php

use App\Models\Sale;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

state(['search' => '', 'status' => 'all']);

$sales = computed(function () {
    return Sale::query()
        ->where('company_id', auth('customer')->user()->company_id)->where('customer_id', auth('customer')->user()->customer_id)
        ->where('status', 'completed')
        ->when($this->status !== 'all', fn ($query) => $query->where('payment_status', $this->status))
        ->when($this->search, fn ($query) => $query->where('sale_number', 'like', '%'.$this->search.'%'))
        ->latest('sale_date')
        ->paginate(10);
});
$paymentStatusLabel = fn (string $status) => [
    'paid' => __('messages.status.paid'),
    'partial' => __('messages.status.partial'),
    'unpaid' => __('messages.status.unpaid'),
][$status] ?? \App\Support\UiText::label($status);

?>

<div>
<nav class="mb-5 grid grid-cols-2 gap-3"><a href="{{ route('customer.invoices.index') }}" wire:navigate class="flex min-h-14 items-center justify-center rounded-xl border border-slate-200 bg-white p-3 text-center text-sm font-black dark:border-slate-700 dark:bg-slate-900">{{ __('portal.invoices') }}</a><a href="{{ route('customer.receipts.create') }}" wire:navigate class="flex min-h-14 items-center justify-center rounded-xl border border-slate-200 bg-white p-3 text-center text-sm font-black dark:border-slate-700 dark:bg-slate-900">{{ __('messages.nav.upload_receipt') }}</a><a href="{{ route('customer.deposits.index') }}" wire:navigate class="flex min-h-14 items-center justify-center rounded-xl border border-slate-200 bg-white p-3 text-center text-sm font-black dark:border-slate-700 dark:bg-slate-900">{{ __('messages.nav.deposits') }}</a><a href="{{ route('customer.statement') }}" wire:navigate class="flex min-h-14 items-center justify-center rounded-xl border border-slate-200 bg-white p-3 text-center text-sm font-black dark:border-slate-700 dark:bg-slate-900">{{ __('messages.nav.statements') }}</a></nav>
    <x-page-header :title="__('messages.debts.title')" :description="__('messages.debts.description')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.debts.title') => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('messages.debts.search') }}">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="all">{{ __('messages.debts.all_statuses') }}</option><option value="unpaid">{{ __('messages.status.unpaid') }}</option><option value="partial">{{ __('messages.status.partial') }}</option><option value="paid">{{ __('messages.status.paid') }}</option>
            </select>
        </div>
        <x-table :headers="[__('messages.debts.purchase_date'), __('messages.debts.invoice_number'), __('messages.debts.total_amount'), __('messages.debts.paid_amount'), __('messages.debts.balance'), __('messages.debts.status'), '']">
            @forelse ($this->sales as $sale)
                <tr>
                    <td class="px-4 py-3">{{ $sale->sale_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $sale->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($sale->payment_status === 'partial' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200') }}">{{ $this->paymentStatusLabel($sale->payment_status) }}</span></td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('customer.debts.show', $sale) }}" wire:navigate class="text-sm font-black text-build-orange">{{ __('messages.debts.view') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('messages.debts.no_sales') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->sales->links() }}</div>
    </x-card>
</div>
