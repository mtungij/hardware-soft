<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'search' => '',
    'status' => '',
    'payment_status' => '',
    'sale_type' => '',
    'stock_location_id' => '',
    'customer_id' => '',
    'date_from' => '',
    'date_to' => '',
]);

mount(function () {
    $this->search = request('search', $this->search);
    $this->status = request('status', $this->status);
    $this->payment_status = request('payment_status', $this->payment_status);
    $this->sale_type = request('sale_type', $this->sale_type);
    $this->stock_location_id = request('stock_location_id', $this->stock_location_id);
    $this->customer_id = request('customer_id', $this->customer_id);
    $this->date_from = request('date_from', $this->date_from);
    $this->date_to = request('date_to', $this->date_to);
});

?>

<div>
    <x-page-header title="Sales" description="Review POS transactions, customer balances, receipts, and payment follow-ups." :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => null]">
        <x-export-actions export="tables.sales" :params="compact('search', 'status', 'payment_status', 'sale_type', 'stock_location_id', 'customer_id', 'date_from', 'date_to')" />
        @role('Super Admin|Admin|Manager|Cashier')
            <a href="{{ route('pos.index') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white shadow-sm">Open POS</a>
        @endrole
    </x-page-header>

    <x-card>
        <div class="grid gap-3 md:grid-cols-8">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Sale #, customer, cashier">
            <select wire:model.live="status" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="refunded">Refunded</option>
            </select>
            <select wire:model.live="payment_status" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All payments</option>
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="partial">Partial</option>
                <option value="unpaid">Unpaid</option>
            </select>
            <select wire:model.live="sale_type" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All sale types</option>
                <option value="retail">Retail</option>
                <option value="wholesale">Wholesale</option>
            </select>
            <select wire:model.live="stock_location_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All stock locations</option>
                @foreach (StockLocation::where('branch_id', InventorySettings::branchId())->whereIn('type', ['store', 'dispensing'])->orderBy('type')->get() as $location)
                    <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                @endforeach
            </select>
            <select wire:model.live="customer_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All customers</option>
                @foreach (Customer::orderBy('name')->get() as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>
    </x-card>

    @php
        $sales = Sale::query()
            ->with(['customer', 'soldBy', 'createdBy', 'items.stockLocation'])
            ->when($search, fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('createdBy', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($payment_status === 'pending', fn ($query) => $query->whereIn('payment_status', ['unpaid', 'partial']))
            ->when($payment_status && $payment_status !== 'pending', fn ($query) => $query->where('payment_status', $payment_status))
            ->when($sale_type, fn ($query) => $query->where('sale_type', $sale_type))
            ->when($stock_location_id, fn ($query) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('stock_location_id', $stock_location_id)))
            ->when($customer_id, fn ($query) => $query->where('customer_id', $customer_id))
            ->when($date_from, fn ($query) => $query->whereDate('sale_date', '>=', $date_from))
            ->when($date_to, fn ($query) => $query->whereDate('sale_date', '<=', $date_to))
            ->latest()
            ->paginate(12);
    @endphp

    <x-card class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Sale</th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-left">Sale Type</th>
                    <th class="px-4 py-3 text-left">Stock Location</th>
                    <th class="px-4 py-3 text-left">User</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Paid</th>
                    <th class="px-4 py-3 text-right">Balance</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </x-slot:head>
            @forelse ($sales as $sale)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">
                        <p class="font-bold">{{ $sale->sale_number }}</p>
                        <p class="text-xs text-slate-500">{{ $sale->sale_date?->format('M d, Y') }}</p>
                    </td>
                    <td class="px-4 py-3">{{ $sale->customer?->name ?? 'Walk-in Customer' }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->saleType() === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ $sale->saleTypeLabel() }}</span>
                    </td>
                    <td class="px-4 py-3">{{ $sale->stockLocationLabel() }}</td>
                    <td class="px-4 py-3">{{ $sale->soldBy?->name ?? $sale->createdBy?->name }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $sale->total_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->paid_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300' }}">{{ ucfirst($sale->status) }}</span>
                        <span class="ml-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ ucfirst($sale->payment_status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="text-sm font-bold text-navy-700 dark:text-white">View</a>
                            <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="text-sm font-bold text-build-orange">Receipt</a>
                            @if ($sale->status === 'completed' && (float) $sale->balance_amount > 0)
                                <a href="{{ route('sales.payments', $sale) }}" wire:navigate class="text-sm font-bold text-emerald-700 dark:text-emerald-300">Payment</a>
                            @endif
                            @role('Super Admin|Admin')
                                @if ($sale->status === 'completed')
                                    <a href="{{ route('sales.cancel', $sale) }}" wire:navigate class="text-sm font-bold text-red-600">Cancel</a>
                                @endif
                            @endrole
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-10 text-center text-sm text-slate-500">No sales found.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $sales->links() }}</div>
    </x-card>
</div>
