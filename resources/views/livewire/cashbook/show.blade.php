<?php

use App\Models\CashbookSession;
use App\Services\CashbookService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state(['cashbookSession' => null, 'actual_cash' => '', 'notes' => '']);

rules(['actual_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);

mount(function (CashbookSession $cashbookSession, CashbookService $cashbook) {
    $this->cashbookSession = $cashbook->refreshSession($cashbookSession)->load(['branch', 'openedBy', 'closedBy']);
    $this->actual_cash = (string) $this->cashbookSession->actual_cash;
    $this->notes = $this->cashbookSession->notes;
});

$refreshTotals = function (CashbookService $cashbook) {
    $this->cashbookSession = $cashbook->refreshSession($this->cashbookSession)->load(['branch', 'openedBy', 'closedBy']);
    session()->flash('success', __('application.cashbook_totals_refreshed_1b033e48'));
};

$closeSession = function (CashbookService $cashbook) {
    $data = $this->validate();
    $this->cashbookSession = $cashbook->closeSession($this->cashbookSession, (float) $data['actual_cash'], auth()->id(), $data['notes'] ?: null)->load(['branch', 'openedBy', 'closedBy']);
    session()->flash('success', __('application.cashbook_session_closed_f98a1c9d'));
};

?>

<div>
    <x-page-header title="{{ __('application.cashbook_session_ff33a04c') }}" :description="$cashbookSession->session_date?->locale(app()->getLocale())->translatedFormat('M d, Y')" :breadcrumbs="['Dashboard' => route('dashboard'), 'Cashbook' => route('cashbook.index'), 'Session' => null]">
        @if ($cashbookSession->status === 'open')
            <button wire:click="refreshTotals" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.refresh_totals_44515bf1') }}</button>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-card><p class="text-sm text-slate-500">{{ __('application.opening_cash_b758840a') }}</p><p class="mt-2 text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->opening_cash) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.cash_in_cf5d476c') }}</p><p class="mt-2 text-xl font-black text-emerald-600">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->cash_in) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.cash_out_e6b54fb5') }}</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->cash_out) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.expected_cash_06ad8947') }}</p><p class="mt-2 text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->expected_cash) }}</p></x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_380px]">
        <x-card title="{{ __('application.daily_cash_movement_ae024406') }}">
            <x-table :headers="[__('application.metric_b2bb7604'), __('application.amount_43dc8532')]">
                <tr><td class="px-4 py-3">{{ __('application.cash_sales_2d1ab6e2') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->cash_sales) }}</td></tr>
                <tr><td class="px-4 py-3">{{ __('application.customer_payments_62a7db50') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->customer_payments) }}</td></tr>
                <tr><td class="px-4 py-3">{{ __('application.supplier_payments_81f23e8c') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->supplier_payments) }}</td></tr>
                <tr><td class="px-4 py-3">{{ __('application.expenses_8d437dbf') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->expenses) }}</td></tr>
                <tr><td class="px-4 py-3 font-bold">{{ __('application.difference_b16f1f66') }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($cashbookSession->difference) }}</td></tr>
            </x-table>
        </x-card>

        <x-card title="{{ __('application.close_session_e95fb979') }}">
            @if ($cashbookSession->status === 'closed')
                <p class="rounded-lg bg-slate-100 p-4 text-sm font-semibold dark:bg-white/5">{{ __('application.closed_by_1b17f3c8') }} {{ $cashbookSession->closedBy?->name }} {{ __('application.on_db3d405b') }} {{ $cashbookSession->closed_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }}.</p>
            @else
                @can('manage cashbook')
                    <form wire:submit="closeSession" class="space-y-4">
                        <x-money-input label="{{ __('application.actual_cash_89bcaa6e') }}" name="actual_cash" wire:model="actual_cash" required />
                        <label class="block text-sm font-bold">{{ __('application.notes_70440046') }}<textarea wire:model="notes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea></label>
                        <button class="w-full rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.close_cashbook_9cf0d4dd') }}</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500">{{ __('application.you_can_view_this_session_but_only_accounting_managers_can_close_it_59c97660') }}</p>
                @endcan
            @endif
        </x-card>
    </div>
</div>
