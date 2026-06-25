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
    session()->flash('success', 'Cashbook totals refreshed.');
};

$closeSession = function (CashbookService $cashbook) {
    $data = $this->validate();
    $this->cashbookSession = $cashbook->closeSession($this->cashbookSession, (float) $data['actual_cash'], auth()->id(), $data['notes'] ?: null)->load(['branch', 'openedBy', 'closedBy']);
    session()->flash('success', 'Cashbook session closed.');
};

?>

<div>
    <x-page-header title="Cashbook Session" :description="$cashbookSession->session_date?->format('M d, Y')" :breadcrumbs="['Dashboard' => route('dashboard'), 'Cashbook' => route('cashbook.index'), 'Session' => null]">
        @if ($cashbookSession->status === 'open')
            <button wire:click="refreshTotals" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Refresh Totals</button>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-card><p class="text-sm text-slate-500">Opening Cash</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $cashbookSession->opening_cash, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Cash In</p><p class="mt-2 text-xl font-black text-emerald-600">TZS {{ number_format((float) $cashbookSession->cash_in, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Cash Out</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ number_format((float) $cashbookSession->cash_out, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Expected Cash</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $cashbookSession->expected_cash, 2) }}</p></x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_380px]">
        <x-card title="Daily Cash Movement">
            <x-table :headers="['Metric', 'Amount']">
                <tr><td class="px-4 py-3">Cash sales</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $cashbookSession->cash_sales, 2) }}</td></tr>
                <tr><td class="px-4 py-3">Customer payments</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $cashbookSession->customer_payments, 2) }}</td></tr>
                <tr><td class="px-4 py-3">Supplier payments</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $cashbookSession->supplier_payments, 2) }}</td></tr>
                <tr><td class="px-4 py-3">Expenses</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $cashbookSession->expenses, 2) }}</td></tr>
                <tr><td class="px-4 py-3 font-bold">Difference</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $cashbookSession->difference, 2) }}</td></tr>
            </x-table>
        </x-card>

        <x-card title="Close Session">
            @if ($cashbookSession->status === 'closed')
                <p class="rounded-lg bg-slate-100 p-4 text-sm font-semibold dark:bg-white/5">Closed by {{ $cashbookSession->closedBy?->name }} on {{ $cashbookSession->closed_at?->format('M d, Y H:i') }}.</p>
            @else
                @can('manage cashbook')
                    <form wire:submit="closeSession" class="space-y-4">
                        <x-money-input label="Actual Cash" name="actual_cash" wire:model="actual_cash" required />
                        <label class="block text-sm font-bold">Notes<textarea wire:model="notes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea></label>
                        <button class="w-full rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Close Cashbook</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500">You can view this session, but only accounting managers can close it.</p>
                @endcan
            @endif
        </x-card>
    </div>
</div>
