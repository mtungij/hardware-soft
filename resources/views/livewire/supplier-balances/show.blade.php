<?php

use App\Models\Supplier;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['supplier' => null]);
mount(fn (Supplier $supplier) => $this->supplier = $supplier->load(['purchases', 'payments.paidBy', 'payments.purchase']));

?>

<div>
    @php
        $balance = app(AccountingService::class)->supplierBalance($supplier);
        $runningBalance = (float) $supplier->opening_balance;
        $ledgerRows = $supplier->purchases
            ->where('status', '!=', 'cancelled')
            ->map(fn ($purchase) => [
                'date' => $purchase->purchase_date,
                'sort_order' => 0,
                'type' => 'Purchase',
                'reference' => $purchase->reference_number,
                'debit' => (float) $purchase->total_amount,
                'credit' => 0.0,
            ])
            ->concat($supplier->payments->map(fn ($payment) => [
                'date' => $payment->payment_date,
                'sort_order' => 1,
                'type' => 'Payment',
                'reference' => $payment->reference_number ?: $payment->purchase?->reference_number ?: '-',
                'debit' => 0.0,
                'credit' => (float) $payment->amount,
            ]))
            ->sortBy(fn ($row) => ($row['date']?->format('Y-m-d') ?? '').'-'.$row['sort_order'])
            ->values()
            ->map(function ($row) use (&$runningBalance) {
                $runningBalance += $row['debit'] - $row['credit'];
                $row['balance'] = $runningBalance;

                return $row;
            });
    @endphp
    <x-page-header title="Supplier Statement" :description="$supplier->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Supplier Balances' => route('supplier-balances.index'), $supplier->name => null]">
        <a href="{{ route('supplier-payments.create', ['supplier_id' => $supplier->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Pay Supplier</a>
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Opening</p><p class="mt-2 text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($supplier->opening_balance) }}</p></x-card><x-card><p class="text-sm text-slate-500">Outstanding</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($balance) }}</p></x-card><x-card><p class="text-sm text-slate-500">Purchases</p><p class="mt-2 text-xl font-black">{{ $supplier->purchases->count() }}</p></x-card></div>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Purchases"><x-table :headers="['Date', 'Reference', 'Total', 'Balance']">@foreach ($supplier->purchases()->latest()->get() as $purchase)<tr><td class="px-4 py-3">{{ $purchase->purchase_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $purchase->reference_number }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</td></tr>@endforeach</x-table></x-card>
        <x-card title="Payments"><x-table :headers="['Date', 'Method', 'Reference', 'Amount']">@foreach ($supplier->payments()->latest()->get() as $payment)<tr><td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td></tr>@endforeach</x-table></x-card>
    </div>
    <div class="mt-6">
        <x-card title="Supplier Ledger">
            <x-table :headers="['Date', 'Entry', 'Reference', 'Debit / Owed', 'Credit / Paid', 'Running Balance']">
                @forelse ($ledgerRows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date']?->format('M d, Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $row['type'] }}</td>
                        <td class="px-4 py-3">{{ $row['reference'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['debit'] > 0 ? 'TZS '.\App\Support\NumberFormatter::money($row['debit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['credit'] > 0 ? 'TZS '.\App\Support\NumberFormatter::money($row['credit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right font-black">TZS {{ \App\Support\NumberFormatter::money($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No supplier ledger entries found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
