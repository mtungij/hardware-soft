<?php

use App\Models\Supplier;
use App\Services\AccountingService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '']);

?>

<div>
    <x-page-header title="Supplier Balances" description="Track supplier statements, outstanding purchases, and payments." :breadcrumbs="['Dashboard' => route('dashboard'), 'Supplier Balances' => null]">
        <a href="{{ route('supplier-payments.create') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Pay Supplier</a>
    </x-page-header>
    @php
        $accounting = app(AccountingService::class);
        $suppliers = Supplier::query()->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->withCount('purchases')->paginate(12);
        $total = Supplier::query()->get()->sum(fn ($supplier) => $accounting->supplierBalance($supplier));
    @endphp
    <div class="grid gap-4 sm:grid-cols-2"><x-card><p class="text-sm text-slate-500">Supplier Outstanding</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ number_format((float) $total, 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Suppliers</p><p class="mt-2 text-2xl font-black">{{ Supplier::count() }}</p></x-card></div>
    <x-card class="mt-6">
        <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search suppliers">
        <x-table :headers="['Supplier', 'Opening', 'Outstanding', 'Purchases', 'Actions']">
            @foreach ($suppliers as $supplier)
                @php $balance = $accounting->supplierBalance($supplier); @endphp
                <tr><td class="px-4 py-3"><p class="font-bold">{{ $supplier->name }}</p><p class="text-xs text-slate-500">{{ $supplier->phone }}</p></td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $supplier->opening_balance, 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format($balance, 2) }}</td><td class="px-4 py-3">{{ $supplier->purchases_count }}</td><td class="px-4 py-3 text-right"><a href="{{ route('supplier-balances.show', $supplier) }}" wire:navigate class="text-sm font-bold text-build-orange">Statement</a></td></tr>
            @endforeach
        </x-table>
        <div class="mt-4">{{ $suppliers->links() }}</div>
    </x-card>
</div>
