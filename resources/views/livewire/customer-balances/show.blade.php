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
    'branch_id' => '',
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
    $this->branch_id = request('branch_id', '');
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

    <x-page-header title="Customer Statement" :description="$customer->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), $customer->name => null]">
        <button type="button" onclick="window.print()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Print Statement</button>
        <a href="{{ route('customer-payments.create', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Record Payment</a>
    </x-page-header>

    <x-card>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div><p class="text-xs font-black uppercase text-slate-400">Customer</p><p class="font-black">{{ $customer->name }}</p><p class="text-sm text-slate-500">{{ $customer->phone ?: '-' }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">Address</p><p class="font-bold">{{ $customer->address ?: '-' }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">Branch</p><p class="font-bold">{{ $customer->branch?->name ?? 'Global' }}</p></div>
            <div><p class="text-xs font-black uppercase text-slate-400">Statement Period</p><p class="font-bold">{{ $date_from }} to {{ $date_to }}</p></div>
        </div>
    </x-card>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-card><p class="text-sm text-slate-500">Opening Balance</p><p class="mt-2 text-xl font-black">{{ $money($openingBalance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Total Credit Purchases</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($totalCreditPurchases) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Total Payments</p><p class="mt-2 text-xl font-black text-emerald-600">{{ $money($totalPayments) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Closing Balance</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($openingBalance + $totalCreditPurchases - $totalPayments) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Current Outstanding</p><p class="mt-2 text-xl font-black text-red-600">{{ $money($balance) }}</p></x-card>
    </div>

    <x-card class="mt-4">
        <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
            <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <select wire:model.live="branch_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All Branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="transaction_type" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All Transactions</option><option value="credit_sale">Credit Sale</option><option value="payment">Payment</option><option value="return">Return</option><option value="adjustment">Adjustment</option></select>
            <input wire:model.live.debounce.300ms="sale_number" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Sale number">
            <select wire:model.live="product_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All Products</option>@foreach (Product::with('size')->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->displayNameWithSize() }}</option>@endforeach</select>
            <select wire:model.live="payment_method" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All Methods</option><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank">Bank</option></select>
        </div>
    </x-card>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach (['statement' => 'Statement', 'purchases' => 'Credit Purchases', 'payments' => 'Payments', 'outstanding' => 'Outstanding Sales'] as $tab => $label)
            <button type="button" wire:click="setTab('{{ $tab }}')" class="rounded-lg px-3 py-2 text-sm font-black {{ $activeTab === $tab ? 'bg-build-orange text-white' : 'border border-slate-200 dark:border-slate-700' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($activeTab === 'statement')
        <x-card class="mt-4" x-data="{ open: null }">
            <x-table :headers="['Date', 'Time', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Running Balance', 'Action']">
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
                        <td class="px-4 py-3 text-right"><button type="button" x-on:click="open = open === '{{ $row['type'] }}-{{ $model->id }}' ? null : '{{ $row['type'] }}-{{ $model->id }}'" class="text-sm font-bold text-build-orange">Details</button></td>
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
                    <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No statement transactions found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @elseif ($activeTab === 'purchases')
        <div class="mt-4 space-y-4">
            @forelse ($sales->sortByDesc('sale_date') as $sale)
                <x-card title="{{ $sale->sale_number }}" description="{{ $sale->sale_date?->format('M d, Y') }}">
                    @include('partials.customer-sale-products', ['sale' => $sale, 'money' => $money, 'unitLabel' => $unitLabel, 'baseUnitLabel' => $baseUnitLabel])
                </x-card>
            @empty
                <x-card><p class="py-8 text-center text-slate-500">No credit purchases found.</p></x-card>
            @endforelse
        </div>
    @elseif ($activeTab === 'payments')
        <x-card class="mt-4" title="Payment History">
            <x-table :headers="['Date', 'Time', 'Receipt', 'Amount', 'Method', 'Reference', 'Branch', 'Received By', 'Notes', 'Allocated Sales']">
                @forelse ($payments->sortByDesc('payment_date') as $payment)
                    <tr>
                        <td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td>
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
                    <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No payments found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @else
        <div class="mt-4 space-y-4">
            @forelse ($outstandingSales as $sale)
                <x-card title="{{ $sale->sale_number }}" description="{{ $sale->sale_date?->format('M d, Y') }} / {{ now()->diffInDays($sale->sale_date) }} days outstanding">
                    @include('partials.customer-sale-products', ['sale' => $sale, 'money' => $money, 'unitLabel' => $unitLabel, 'baseUnitLabel' => $baseUnitLabel])
                </x-card>
            @empty
                <x-card><p class="py-8 text-center text-slate-500">No outstanding sales found.</p></x-card>
            @endforelse
        </div>
    @endif
</div>
