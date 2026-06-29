<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () {
    $this->branch_id = request('branch_id', $this->branch_id);
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->search = request('search', $this->search);
});

?>

<div>
    <x-page-header title="Purchase Report" description="Supplier purchases, paid amounts, and outstanding balances." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Purchases' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $rows = collect(app(FinancialReportService::class)->purchases($branch_id ? (int) $branch_id : null, $date_from, $date_to))->filter(fn ($purchase) => blank($search) || str_contains(strtolower($purchase->reference_number.' '.$purchase->supplier?->name), strtolower($search))); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-4"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Purchases</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Total</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('total_amount'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Balance</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('balance_amount'), 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Date', 'Reference', 'Supplier', 'Status', 'Total', 'Paid', 'Balance']">@foreach ($rows as $purchase)<tr><td class="px-4 py-3">{{ $purchase->purchase_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $purchase->reference_number }}</td><td class="px-4 py-3">{{ $purchase->supplier?->name }}</td><td class="px-4 py-3">{{ ucfirst($purchase->payment_status) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $purchase->total_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $purchase->paid_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $purchase->balance_amount, 2) }}</td></tr>@endforeach</x-table></x-card>
</div>
