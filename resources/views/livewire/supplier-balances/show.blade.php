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
    <x-page-header title="{{ __('application.supplier_statement_f5561055') }}" :description="$supplier->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Supplier Balances' => route('supplier-balances.index'), $supplier->name => null]">
        <a href="{{ route('supplier-payments.create', ['supplier_id' => $supplier->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.pay_supplier_d359c630') }}</a>
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">{{ __('application.opening_56e44065') }}</p><p class="mt-2 text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($supplier->opening_balance) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.outstanding_f8ee57ec') }}</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($balance) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.purchases_6c4e94f1') }}</p><p class="mt-2 text-xl font-black">{{ $supplier->purchases->count() }}</p></x-card></div>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="{{ __('application.purchases_6c4e94f1') }}"><x-table :headers="[__('application.date_eb9a4bc1'), __('application.reference_db1c7845'), __('application.total_b25928c6'), __('application.balance_90eef613')]">@foreach ($supplier->purchases()->latest()->get() as $purchase)<tr><td class="px-4 py-3">{{ $purchase->purchase_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $purchase->reference_number }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</td></tr>@endforeach</x-table></x-card>
        <x-card title="{{ __('application.payments_44357ae5') }}"><x-table :headers="[__('application.date_eb9a4bc1'), __('application.method_88306943'), __('application.reference_db1c7845'), __('application.amount_43dc8532')]">@foreach ($supplier->payments()->latest()->get() as $payment)<tr><td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3">{{ \App\Support\UiText::label($payment->payment_method) }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td></tr>@endforeach</x-table></x-card>
    </div>
    <div class="mt-6">
        <x-card title="{{ __('application.supplier_ledger_858d51d4') }}">
            <x-table :headers="[__('application.date_eb9a4bc1'), __('terminology.entry_19172e9e'), __('application.reference_db1c7845'), __('terminology.debit_owed_a896d457'), __('terminology.credit_paid_045798f5'), __('terminology.running_balance_6fe16f48')]">
                @forelse ($ledgerRows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date']?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $row['type'] }}</td>
                        <td class="px-4 py-3">{{ $row['reference'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['debit'] > 0 ? 'TZS '.\App\Support\NumberFormatter::money($row['debit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['credit'] > 0 ? 'TZS '.\App\Support\NumberFormatter::money($row['credit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right font-black">TZS {{ \App\Support\NumberFormatter::money($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_supplier_ledger_entries_found_edc7a463') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
