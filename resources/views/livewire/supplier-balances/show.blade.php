<?php

use App\Models\Supplier;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['supplier' => null]);
mount(fn (Supplier $supplier) => $this->supplier = $supplier->load(['purchases', 'payments.paidBy']));

?>

<div>
    @php $balance = app(AccountingService::class)->supplierBalance($supplier); @endphp
    <x-page-header title="Supplier Statement" :description="$supplier->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Supplier Balances' => route('supplier-balances.index'), $supplier->name => null]">
        <a href="{{ route('supplier-payments.create', ['supplier_id' => $supplier->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Pay Supplier</a>
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Opening</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $supplier->opening_balance, 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Outstanding</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ number_format($balance, 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Purchases</p><p class="mt-2 text-xl font-black">{{ $supplier->purchases->count() }}</p></x-card></div>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Purchases"><x-table :headers="['Date', 'Reference', 'Total', 'Balance']">@foreach ($supplier->purchases()->latest()->get() as $purchase)<tr><td class="px-4 py-3">{{ $purchase->purchase_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $purchase->reference_number }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $purchase->total_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $purchase->balance_amount, 2) }}</td></tr>@endforeach</x-table></x-card>
        <x-card title="Payments"><x-table :headers="['Date', 'Method', 'Reference', 'Amount']">@foreach ($supplier->payments()->latest()->get() as $payment)<tr><td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $payment->amount, 2) }}</td></tr>@endforeach</x-table></x-card>
    </div>
</div>
