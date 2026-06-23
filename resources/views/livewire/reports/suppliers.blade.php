<?php

use App\Models\Supplier;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');
state(['search' => '']);

?>

<div>
    <x-page-header title="Supplier Balance Report" description="Supplier outstanding balances and purchase exposure." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Suppliers' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $accounting = app(AccountingService::class); $rows = Supplier::query()->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))->get()->map(fn ($supplier) => ['supplier' => $supplier, 'balance' => $accounting->supplierBalance($supplier)]); @endphp
    <x-card><input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search supplier"></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Suppliers</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Outstanding</p><p class="text-2xl font-black text-red-600">TZS {{ number_format((float) $rows->sum('balance'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Opening</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum(fn ($row) => $row['supplier']->opening_balance), 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Supplier', 'Phone', 'Opening', 'Outstanding']">@foreach ($rows as $row)<tr><td class="px-4 py-3 font-bold">{{ $row['supplier']->name }}</td><td class="px-4 py-3">{{ $row['supplier']->phone }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $row['supplier']->opening_balance, 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format($row['balance'], 2) }}</td></tr>@endforeach</x-table></x-card>
</div>
