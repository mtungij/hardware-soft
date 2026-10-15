<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\CustomerPayment;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'customer' => null,
    'activeTab' => 'statement',
    'date_from' => '',
    'date_to' => '',
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'transaction_type' => '',
    'sale_number' => '',
    'product_id' => '',
    'payment_method' => '',
]);

mount(function (Customer $customer) {
    $this->customer = $customer->load(['branch']);
    $this->activeTab = request('tab', 'statement');
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->branch_id = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branch_id', '') ? (int) request('branch_id', '') : null) ?? '');
    $this->transaction_type = request('transaction_type', '');
    $this->sale_number = request('sale_number', '');
    $this->product_id = request('product_id', '');
    $this->payment_method = request('payment_method', '');
});

$setTab = fn (string $tab) => $this->activeTab = $tab;

?>

<div>
    @php
        $money = fn ($value) => 'TZS '.\App\Support\NumberFormatter::money($value);
        $quantity = fn ($value) => \App\Support\NumberFormatter::quantity($value);
        $methodLabel = fn ($method) => str((string) $method)->replace('_', ' ')->title();
        $unitLabel = fn ($item) => $item->sellingUnit?->short_name ?: $item->product?->unit?->short_name;
        $baseUnitLabel = fn ($item) => $item->baseUnit?->short_name ?: $item->product?->unit?->short_name;
        $balance = app(AccountingService::class)->customerBalance($customer);

        $salesQuery = Sale::query()
            ->with(['branch', 'items.product.unit', 'items.sellingUnit', 'items.baseUnit', 'payments'])
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))
            ->when($date_from, fn ($query) => $query->whereDate('sale_date', '>=', $date_from))
            ->when($date_to, fn ($query) => $query->whereDate('sale_date', '<=', $date_to))
            ->when($sale_number, fn ($query) => $query->where('sale_number', 'like', "%{$sale_number}%"))
            ->when($product_id, fn ($query) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_id', $product_id)));

        $paymentsQuery = CustomerPayment::query()
            ->with(['branch', 'receivedBy', 'allocations.sale.items.product.unit'])
            ->where('customer_id', $customer->id)
            ->when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))
            ->when($date_from, fn ($query) => $query->whereDate('payment_date', '>=', $date_from))
            ->when($date_to, fn ($query) => $query->whereDate('payment_date', '<=', $date_to))
            ->when($payment_method, fn ($query) => $query->where('payment_method', $payment_method));

        $sales = $salesQuery->oldest('sale_date')->oldest('created_at')->get();
        $payments = $paymentsQuery->oldest('payment_date')->oldest('created_at')->get();

        $openingBalance = (float) $customer->opening_balance
            + (float) Sale::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'completed')
                ->when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))
                ->when($date_from, fn ($query) => $query->whereDate('sale_date', '<', $date_from))
                ->sum('total_amount')
            - (float) CustomerPayment::query()
                ->where('customer_id', $customer->id)
                ->when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))
                ->when($date_from, fn ($query) => $query->whereDate('payment_date', '<', $date_from))
                ->sum('amount');

        $ledgerRows = collect();

        if (! $transaction_type || $transaction_type === 'credit_sale') {
            foreach ($sales as $sale) {
                $ledgerRows->push([
                    'sort' => $sale->sale_date?->toDateString().' '.$sale->created_at?->format('H:i:s'),
                    'type' => 'Credit Sale',
                    'reference' => $sale->sale_number,
                    'description' => $sale->items->map(fn ($item) => $item->productDisplayNameWithSize().' x '.$quantity($item->quantity).' '.$unitLabel($item))->join(', '),
                    'debit' => (float) $sale->total_amount,
                    'credit' => 0,
                    'model' => $sale,
                ]);
            }
        }

        if (! $transaction_type || $transaction_type === 'payment') {
            foreach ($payments as $payment) {
                $ledgerRows->push([
                    'sort' => $payment->payment_date?->toDateString().' '.$payment->created_at?->format('H:i:s'),
                    'type' => 'Payment',
                    'reference' => $payment->receipt_number ?: ($payment->reference_number ?: 'PAY-'.$payment->id),
                    'description' => $methodLabel($payment->payment_method).' payment'.($payment->reference_number ? ' / Ref '.$payment->reference_number : ''),
                    'debit' => 0,
                    'credit' => (float) $payment->amount,
                    'model' => $payment,
                ]);
            }
        }

        $runningBalance = $openingBalance;
        $ledgerRows = $ledgerRows
            ->sortBy('sort')
            ->values()
            ->map(function ($row) use (&$runningBalance) {
                $runningBalance += (float) $row['debit'];
                $runningBalance -= (float) $row['credit'];
                $row['balance'] = $runningBalance;

                return $row;
            });

        $totalCreditPurchases = $sales->sum('total_amount');
        $totalPayments = $payments->sum('amount');
        $outstandingSales = $sales->filter(fn ($sale) => (float) $sale->balance_amount > 0)->values();
    @endphp

    <x-page-header title="{{ __('application.customer_statement_003b2523') }}" :description="$customer->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), $customer->name => null]">
        <button type="button" onclick="window.print()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.print_statement_7f56d1b5') }}</button>
        <a href="{{ route('customer-payments.create', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.record_payment_6577ced3') }}</a>
    </x-page-header>

    <x-card>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div><p class="text-xs font-black uppercase text-slate-400">{{ __('application.customer_0e85749a') }}</p><p class="font-black">{{ $customer->displayName() }}</p><p class="text-sm text-slate-500">{{ $customer->phone ?: '-' }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">{{ __('application.address_d70f93df') }}</p><p class="font-bold">{{ $customer->address ?: '-' }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">{{ __('application.branch_1627510b') }}</p><p class="font-bold">{{ $customer->branch?->name ?? __('terminology.global_5f1184f7') }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">{{ __('application.statement_period_ea980b8b') }}</p><p class="font-bold">{{ $date_from }} {{ __('application.to_4374aaee') }} {{ $date_to }}</p></div>
        </div>
    </x-card>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-card><p class="text-sm text-slate-500">{{ __('application.opening_balance_1a1a6305') }}</p><p class="mt-2 text-xl font-black">{{ $money($openingBalance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.total_credit_purchases_d49f2f63') }}</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($totalCreditPurchases) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.total_payments_53b493df') }}</p><p class="mt-2 text-xl font-black text-emerald-600">{{ $money($totalPayments) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.closing_balance_98fa4269') }}</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($openingBalance + $totalCreditPurchases - $totalPayments) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.current_outstanding_0247d492') }}</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($balance) }}</p></x-card>
    </div>

    <x-card class="mt-4">
        <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
            <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <select wire:model.live="branch_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_0bf51d9a') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="transaction_type" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_transactions_f46e1df2') }}</option><option value="credit_sale">{{ __('application.credit_sale_e7c45bcf') }}</option><option value="payment">{{ __('application.payment_b41a92be') }}</option><option value="return">{{ __('application.return_24f096b2') }}</option><option value="adjustment">{{ __('application.adjustment_7f0496ac') }}</option></select>
            <input wire:model.live.debounce.300ms="sale_number" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('application.sale_number_adb3a494') }}">
            <select wire:model.live="product_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_products_60fe93b6') }}</option>@foreach (Product::with('size')->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->displayNameWithSize() }}</option>@endforeach</select>
            <select wire:model.live="payment_method" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_methods_06a3fcd1') }}</option><option value="cash">{{ __('application.cash_758ec54e') }}</option><option value="mobile_money">{{ __('application.mobile_money_6a38d1e4') }}</option><option value="bank">{{ __('application.bank_9e89988c') }}</option></select>
        </div>
    </x-card>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach (['statement' => 'Statement', 'purchases' => 'Credit Purchases', 'payments' => 'Payments', 'outstanding' => 'Outstanding Sales'] as $tab => $label)
            <button type="button" wire:click="setTab('{{ $tab }}')" class="rounded-lg px-3 py-2 text-sm font-black {{ $activeTab === $tab ? 'bg-build-orange text-white' : 'border border-slate-200 dark:border-slate-700' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($activeTab === 'statement')
        <x-card class="mt-4" x-data="{ open: null }">
            <x-table :headers="[__('application.date_eb9a4bc1'), __('application.time_6c82e6dd'), __('application.type_3deb7456'), __('application.reference_db1c7845'), __('application.description_55f8ebc8'), __('terminology.debit_cf558941'), __('application.credit_8a45d339'), __('terminology.running_balance_6fe16f48'), __('application.action_97c89a4d')]">
                @forelse ($ledgerRows as $row)
                    @php $model = $row['model']; @endphp
                    <tr>
                        <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($row['sort'])->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($row['sort'])->format('H:i') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $row['type'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $row['reference'] }}</td>
                        <td class="px-4 py-3">{{ $row['description'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['debit'] > 0 ? $money($row['debit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['credit'] > 0 ? $money($row['credit']) : '-' }}</td>
                        <td class="px-4 py-3 text-right font-black">{{ $money($row['balance']) }}</td>
                        <td class="px-4 py-3 text-right"><button type="button" x-on:click="open = open === '{{ $row['type'] }}-{{ $model->id }}' ? null : '{{ $row['type'] }}-{{ $model->id }}'" class="text-sm font-bold text-build-orange">{{ __('application.details_dc3decbb') }}</button></td>
                    </tr>
                    <tr x-show="open === '{{ $row['type'] }}-{{ $model->id }}'" style="display: none;">
                        <td colspan="9" class="bg-slate-50 px-4 py-4 dark:bg-white/5">
                            @if ($row['type'] === 'Credit Sale')
                                @include('partials.customer-sale-products', ['sale' => $model, 'money' => $money, 'unitLabel' => $unitLabel, 'baseUnitLabel' => $baseUnitLabel])
                            @else
                                @include('partials.customer-payment-detail', ['payment' => $model, 'money' => $money, 'methodLabel' => $methodLabel])
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_statement_transactions_found_05b65315') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @elseif ($activeTab === 'purchases')
        <div class="mt-4 space-y-4">
            @forelse ($sales->sortByDesc('sale_date') as $sale)
                <x-card title="{{ $sale->sale_number }}" description="{{ $sale->sale_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}">
                    @include('partials.customer-sale-products', ['sale' => $sale, 'money' => $money, 'unitLabel' => $unitLabel, 'baseUnitLabel' => $baseUnitLabel])
                </x-card>
            @empty
                <x-card><p class="py-8 text-center text-slate-500">{{ __('application.no_credit_purchases_found_9c359031') }}</p></x-card>
            @endforelse
        </div>
    @elseif ($activeTab === 'payments')
        <x-card class="mt-4" title="{{ __('application.payment_history_cfeba031') }}">
            <x-table :headers="[__('application.date_eb9a4bc1'), __('application.time_6c82e6dd'), __('application.receipt_f6ce3b6f'), __('application.amount_43dc8532'), __('application.method_88306943'), __('application.reference_db1c7845'), __('application.branch_1627510b'), __('application.received_by_f84f73c6'), __('application.notes_70440046'), __('application.allocated_sales_f84d7aeb')]">
                @forelse ($payments->sortByDesc('payment_date') as $payment)
                    <tr>
                        <td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                        <td class="px-4 py-3">{{ $payment->created_at?->format('H:i') }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $payment->receipt_number ?: 'PAY-'.$payment->id }}</td>
                        <td class="px-4 py-3 text-right font-black">{{ $money($payment->amount) }}</td>
                        <td class="px-4 py-3">{{ $methodLabel($payment->payment_method) }}</td>
                        <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $payment->branch?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $payment->receivedBy?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $payment->notes ?: '-' }}</td>
                        <td class="px-4 py-3">@include('partials.customer-payment-allocations', ['payment' => $payment, 'money' => $money])</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_payments_found_69d803dd') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @else
        <div class="mt-4 space-y-4">
            @forelse ($outstandingSales as $sale)
                <x-card title="{{ $sale->sale_number }}" description="{{ $sale->sale_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }} / {{ now()->diffInDays($sale->sale_date) }} days outstanding">
                    @include('partials.customer-sale-products', ['sale' => $sale, 'money' => $money, 'unitLabel' => $unitLabel, 'baseUnitLabel' => $baseUnitLabel])
                </x-card>
            @empty
                <x-card><p class="py-8 text-center text-slate-500">{{ __('application.no_outstanding_sales_found_20618f1a') }}</p></x-card>
            @endforelse
        </div>
    @endif
</div>
