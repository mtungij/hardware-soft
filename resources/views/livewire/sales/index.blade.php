<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\InventorySettings;
use Illuminate\Pagination\LengthAwarePaginator;

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
    'product_id' => '',
    'category_id' => '',
    'cashier_id' => '',
    'branch_id' => '',
    'payment_method' => '',
    'view' => 'invoices',
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
    $this->product_id = request('product_id', $this->product_id);
    $this->category_id = request('category_id', $this->category_id);
    $this->cashier_id = request('cashier_id', $this->cashier_id);
    $this->branch_id = request('branch_id', $this->branch_id);
    $this->payment_method = request('payment_method', $this->payment_method);
    $this->view = request('view', $this->view);
    $this->date_from = request('date_from', $this->date_from);
    $this->date_to = request('date_to', $this->date_to);
});

?>

<div>
    @php
        $t = fn (string $key): string => __('messages.sales_items.'.$key);
        $money = fn ($value): string => 'TZS '.\App\Support\NumberFormatter::money($value);
        $quantity = fn ($value): string => \App\Support\NumberFormatter::quantity($value);
        $canViewProfit = auth()->user()?->can('view sales profit') ?? false;
        $canExportPdf = auth()->user()?->can('export pdf') ?? false;
        $canExportExcel = auth()->user()?->can('export excel') ?? false;
        $allowedViews = ['items', 'product', 'customer', 'cashier', 'stock_location'];
        $view = in_array($view, ['invoices', ...$allowedViews], true) ? $view : 'invoices';
        $isItemView = $view !== 'invoices';
        $customerLabel = fn ($customer): string => $customer?->name ?: $t('walk_in_customer');
        $cashierLabel = fn ($sale): string => $sale?->soldBy?->name ?: ($sale?->createdBy?->name ?: '-');
        $paymentLabel = fn (string $method): string => $t('method_'.$method);
        $saleTypeLabel = fn (string $type): string => $type === 'wholesale' ? $t('wholesale') : $t('retail');
        $lineCost = fn (SaleItem $item): float => (float) $item->quantity * (float) $item->unit_cost;
        $lineProfit = fn (SaleItem $item): float => (float) ($item->profit_amount ?? ((float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost)));
        $paymentMethods = ['cash', 'mobile_money', 'bank', 'credit'];
        $exportParams = compact('search', 'status', 'payment_status', 'sale_type', 'stock_location_id', 'customer_id', 'product_id', 'category_id', 'cashier_id', 'branch_id', 'payment_method', 'view', 'date_from', 'date_to');

        $itemQuery = function () use ($search, $sale_type, $stock_location_id, $customer_id, $product_id, $category_id, $cashier_id, $branch_id, $payment_method, $date_from, $date_to) {
            return SaleItem::query()
                ->with(['sale.customer', 'sale.soldBy', 'sale.createdBy', 'sale.payments', 'sale.branch', 'product.unit', 'product.category', 'product.size', 'stockLocation'])
                ->whereHas('sale', function ($query) use ($customer_id, $cashier_id, $branch_id, $payment_method, $date_from, $date_to) {
                    $query->where('status', 'completed')
                        ->when($customer_id, fn ($q) => $q->where('customer_id', $customer_id))
                        ->when($cashier_id, fn ($q) => $q->where(fn ($cashierQuery) => $cashierQuery->where('sold_by', $cashier_id)->orWhere('created_by', $cashier_id)))
                        ->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))
                        ->when($payment_method, fn ($q) => $q->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('payment_method', $payment_method)))
                        ->when($date_from, fn ($q) => $q->whereDate('sale_date', '>=', $date_from))
                        ->when($date_to, fn ($q) => $q->whereDate('sale_date', '<=', $date_to));
                })
                ->when($sale_type, fn ($query) => $query->where('sale_type', $sale_type))
                ->when($stock_location_id, fn ($query) => $query->where('stock_location_id', $stock_location_id))
                ->when($product_id, fn ($query) => $query->where('product_id', $product_id))
                ->when($category_id, fn ($query) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('category_id', $category_id)))
                ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                    ->whereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                        ->where('sale_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('soldBy', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('createdBy', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")))));
        };
    @endphp

    <x-page-header :title="$view === 'items' ? $t('todays_sales_summary') : ($isItemView ? $t('daily_sales_product_details') : $t('sales'))" :description="$isItemView ? $t('daily_sales_description') : $t('sales_description')" :breadcrumbs="['Dashboard' => route('dashboard'), $t('sales') => null]">
        <x-export-actions :export="$isItemView ? 'tables.sales-items' : 'tables.sales'" :params="$exportParams" />
        @role('Super Admin|Admin|Manager|Cashier')
            <a href="{{ route('pos.index') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white shadow-sm">{{ $t('open_pos') }}</a>
        @endrole
    </x-page-header>

    <x-card>
        <div class="grid gap-3 md:grid-cols-6 xl:grid-cols-10">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t('search_placeholder') }}">
            <select wire:model.live="view" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="invoices">{{ $t('invoice_view') }}</option>
                <option value="items">{{ $t('todays_sales_summary') }}</option>
                <option value="product">{{ $t('group_by_product') }}</option>
                <option value="customer">{{ $t('group_by_customer') }}</option>
                <option value="cashier">{{ $t('group_by_cashier') }}</option>
                <option value="stock_location">{{ $t('group_by_stock_location') }}</option>
            </select>
            @if (! $isItemView)
                <select wire:model.live="status" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ $t('all_statuses') }}</option>
                    <option value="completed">{{ $t('completed') }}</option>
                    <option value="cancelled">{{ $t('cancelled') }}</option>
                    <option value="refunded">{{ $t('refunded') }}</option>
                </select>
                <select wire:model.live="payment_status" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ $t('all_payments') }}</option>
                    <option value="pending">{{ $t('pending') }}</option>
                    <option value="paid">{{ $t('paid') }}</option>
                    <option value="partial">{{ $t('partial') }}</option>
                    <option value="unpaid">{{ $t('unpaid') }}</option>
                </select>
            @endif
            <select wire:model.live="sale_type" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_sale_types') }}</option>
                <option value="retail">{{ $t('retail') }}</option>
                <option value="wholesale">{{ $t('wholesale') }}</option>
            </select>
            <select wire:model.live="stock_location_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_stock_locations') }}</option>
                @foreach (StockLocation::when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))->whereIn('type', ['store', 'dispensing'])->orderBy('type')->get() as $location)
                    <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                @endforeach
            </select>
            <select wire:model.live="customer_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_customers') }}</option>
                @foreach (Customer::orderBy('name')->get() as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="product_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_products') }}</option>
                @foreach (Product::with('size')->orderBy('name')->get() as $product)
                    <option value="{{ $product->id }}">{{ $product->displayNameWithSize() }} / {{ $product->sku }}</option>
                @endforeach
            </select>
            <select wire:model.live="category_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_categories') }}</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="cashier_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_cashiers') }}</option>
                @foreach (User::orderBy('name')->get() as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="payment_method" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_payment_methods') }}</option>
                @foreach ($paymentMethods as $method)
                    <option value="{{ $method }}">{{ $paymentLabel($method) }}</option>
                @endforeach
            </select>
            <select wire:model.live="branch_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ $t('all_branches') }}</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>
    </x-card>

    @if ($isItemView)
        @php
            $summaryItems = $itemQuery()->get();
            $invoiceRows = $summaryItems
                ->groupBy('sale_id')
                ->map(function ($items) use ($lineCost, $lineProfit, $customerLabel, $cashierLabel, $paymentLabel, $saleTypeLabel) {
                    $sale = $items->first()->sale;
                    $saleTypes = $items->pluck('sale_type')->filter()->unique()->values();
                    $stockSources = $items->map(fn ($item) => $item->sold_from_label ?: ($item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : '-'))->filter()->unique()->values();
                    $paymentMethods = $sale?->payments?->pluck('payment_method')->filter()->unique()->values() ?? collect();

                    return [
                        'sale' => $sale,
                        'items' => $items->values(),
                        'customer' => $customerLabel($sale?->customer),
                        'cashier' => $cashierLabel($sale),
                        'total_quantity' => $items->sum('quantity'),
                        'cost' => $items->sum(fn ($item) => $lineCost($item)),
                        'sales' => (float) ($sale?->total_amount ?? $items->sum('line_total')),
                        'profit' => (float) ($sale?->total_amount ?? $items->sum('line_total')) - $items->sum(fn ($item) => $lineCost($item)),
                        'sale_type' => $saleTypes->count() > 1 ? __('messages.sales_items.mixed') : $saleTypeLabel((string) ($saleTypes->first() ?: 'retail')),
                        'payment_method' => $sale?->payment_status === 'partial' ? __('messages.sales_items.partial') : ($paymentMethods->count() > 1 ? __('messages.sales_items.mixed') : ($paymentMethods->isEmpty() ? '-' : $paymentLabel((string) $paymentMethods->first()))),
                        'stock_source' => $stockSources->count() > 1 ? __('messages.sales_items.mixed_locations') : (string) ($stockSources->first() ?: '-'),
                    ];
                })
                ->sortByDesc(fn ($row) => $row['sale']?->created_at)
                ->values();
            $filteredSaleIds = $invoiceRows->pluck('sale.id')->filter()->unique()->values();
            $summary = [
                ['label' => $t('total_sales'), 'value' => $money($invoiceRows->sum('sales')), 'tone' => 'text-emerald-600'],
                ...($canViewProfit ? [
                    ['label' => $t('total_cost'), 'value' => $money($invoiceRows->sum('cost')), 'tone' => 'text-red-600'],
                    ['label' => $t('total_profit'), 'value' => $money($invoiceRows->sum('profit')), 'tone' => 'text-cyan-600'],
                ] : []),
                ['label' => $t('total_invoices'), 'value' => number_format($invoiceRows->count()), 'tone' => 'text-navy-900 dark:text-white'],
                ['label' => $t('total_customers'), 'value' => number_format($invoiceRows->pluck('sale.customer_id')->filter()->unique()->count() + ($invoiceRows->contains(fn ($row) => blank($row['sale']?->customer_id)) ? 1 : 0)), 'tone' => 'text-navy-900 dark:text-white'],
                ['label' => $t('total_products_sold'), 'value' => $quantity($invoiceRows->sum('total_quantity')), 'tone' => 'text-navy-900 dark:text-white'],
            ];
        @endphp

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach ($summary as $card)
                <x-card>
                    <p class="text-xs font-bold uppercase text-slate-500">{{ $card['label'] }}</p>
                    <p class="mt-2 text-xl font-black {{ $card['tone'] }}">{{ $card['value'] }}</p>
                </x-card>
            @endforeach
        </div>

        @if ($view === 'items')
            @php
                $page = LengthAwarePaginator::resolveCurrentPage();
                $invoices = new LengthAwarePaginator($invoiceRows->forPage($page, 15)->values(), $invoiceRows->count(), 15, $page, ['path' => request()->url(), 'query' => request()->query()]);
            @endphp
            <x-card class="mt-6" x-data="{ openInvoice: null, productInvoice: null }">
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="px-3 py-3 text-left">{{ $t('sale_no') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('customer') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('products_sold') }}</th>
                            <th class="px-3 py-3 text-right">{{ $t('total_quantity') }}</th>
                            @if ($canViewProfit)
                                <th class="px-3 py-3 text-right">{{ $t('buying_cost') }}</th>
                            @endif
                            <th class="px-3 py-3 text-right">{{ $t('selling_amount') }}</th>
                            @if ($canViewProfit)
                                <th class="px-3 py-3 text-right">{{ $t('profit') }}</th>
                            @endif
                            <th class="px-3 py-3 text-left">{{ $t('sale_type') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('payment_method') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('stock_location') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('cashier') }}</th>
                            <th class="px-3 py-3 text-right">{{ $t('actions') }}</th>
                        </tr>
                    </x-slot:head>
                    @forelse ($invoices as $row)
                        @php
                            $sale = $row['sale'];
                            $visibleItems = $row['items']->take(5);
                            $hiddenCount = max(0, $row['items']->count() - 5);
                            $colspan = $canViewProfit ? 12 : 10;
                        @endphp
                            <tr class="align-top text-xs">
                                <td class="px-3 py-3">
                                    <p class="font-black">{{ $sale?->sale_number }}</p>
                                    <p class="mt-1 text-slate-500">{{ $sale?->created_at?->format('H:i') }}</p>
                                </td>
                                <td class="px-3 py-3 font-semibold">{{ $row['customer'] }}</td>
                                <td class="px-3 py-3">
                                    <div class="space-y-1">
                                        @foreach ($visibleItems as $item)
                                            <p>{{ $item->product?->displayNameWithSize() }} x {{ $quantity($item->quantity) }} {{ $item->product?->unit?->short_name }}</p>
                                        @endforeach
                                        @if ($hiddenCount > 0)
                                            <div x-show="productInvoice === {{ $sale?->id ?? 0 }}" class="space-y-1" style="display: none;">
                                                @foreach ($row['items']->skip(5) as $item)
                                                    <p>{{ $item->product?->displayNameWithSize() }} x {{ $quantity($item->quantity) }} {{ $item->product?->unit?->short_name }}</p>
                                                @endforeach
                                            </div>
                                            <button type="button" x-on:click="productInvoice = productInvoice === {{ $sale?->id ?? 0 }} ? null : {{ $sale?->id ?? 0 }}" class="text-xs font-black text-cyan-600">
                                                <span x-show="productInvoice !== {{ $sale?->id ?? 0 }}">+{{ $hiddenCount }} {{ $t('more_products') }}</span>
                                                <span x-show="productInvoice === {{ $sale?->id ?? 0 }}" style="display: none;">{{ $t('show_less') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right font-bold">{{ $quantity($row['total_quantity']) }} {{ $t('items_label') }}</td>
                                @if ($canViewProfit)
                                    <td class="px-3 py-3 text-right">{{ $money($row['cost']) }}</td>
                                @endif
                                <td class="px-3 py-3 text-right font-bold">{{ $money($row['sales']) }}</td>
                                @if ($canViewProfit)
                                    <td class="px-3 py-3 text-right font-bold {{ $row['profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $money($row['profit']) }}</td>
                                @endif
                                <td class="px-3 py-3">{{ $row['sale_type'] }}</td>
                                <td class="px-3 py-3">{{ $row['payment_method'] }}</td>
                                <td class="px-3 py-3">{{ $row['stock_source'] }}</td>
                                <td class="px-3 py-3">{{ $row['cashier'] }}</td>
                                <td class="px-3 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" x-on:click="openInvoice = openInvoice === {{ $sale?->id ?? 0 }} ? null : {{ $sale?->id ?? 0 }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black dark:border-slate-700">
                                            <span x-show="openInvoice !== {{ $sale?->id ?? 0 }}">{{ $t('expand') }}</span>
                                            <span x-show="openInvoice === {{ $sale?->id ?? 0 }}" style="display: none;">{{ $t('collapse') }}</span>
                                        </button>
                                        <x-dropdown align="right" width="48">
                                            <x-slot:trigger>
                                                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black dark:border-slate-700">{{ $t('actions') }}</button>
                                            </x-slot:trigger>
                                            <x-slot:content>
                                                <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10">{{ $t('view_receipt') }}</a>
                                                <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10">{{ $t('print_receipt') }}</a>
                                                @if ($canExportPdf)
                                                    <a href="{{ route('exports.download', ['export' => 'tables.sales-items', 'format' => 'pdf', 'search' => $sale?->sale_number]) }}" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10">{{ $t('download_pdf') }}</a>
                                                @endif
                                                @if ($canExportExcel)
                                                    <a href="{{ route('exports.download', ['export' => 'tables.sales-items', 'format' => 'excel', 'search' => $sale?->sale_number]) }}" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10">{{ $t('download_excel') }}</a>
                                                @endif
                                            </x-slot:content>
                                        </x-dropdown>
                                    </div>
                                </td>
                            </tr>
                            <tr x-show="openInvoice === {{ $sale?->id ?? 0 }}" style="display: none;">
                                <td colspan="{{ $colspan }}" class="bg-slate-50 px-3 py-4 dark:bg-white/5">
                                    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                                        <table class="min-w-full text-xs">
                                            <thead class="bg-slate-100 text-left uppercase text-slate-500 dark:bg-slate-800">
                                                <tr>
                                                    <th class="px-3 py-2">{{ $t('product_name') }}</th>
                                                    <th class="px-3 py-2">{{ $t('sku') }}</th>
                                                    <th class="px-3 py-2 text-right">{{ $t('quantity_sold') }}</th>
                                                    <th class="px-3 py-2">{{ $t('unit') }}</th>
                                                    @if ($canViewProfit)
                                                        <th class="px-3 py-2 text-right">{{ $t('buying_price_unit') }}</th>
                                                    @endif
                                                    <th class="px-3 py-2 text-right">{{ $t('selling_price_unit') }}</th>
                                                    @if ($canViewProfit)
                                                        <th class="px-3 py-2 text-right">{{ $t('total_cost') }}</th>
                                                    @endif
                                                    <th class="px-3 py-2 text-right">{{ $t('total_sales') }}</th>
                                                    @if ($canViewProfit)
                                                        <th class="px-3 py-2 text-right">{{ $t('profit') }}</th>
                                                    @endif
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                @foreach ($row['items'] as $item)
                                                    <tr>
                                                        <td class="px-3 py-2 font-bold">{{ $item->product?->displayNameWithSize() }}</td>
                                                        <td class="px-3 py-2 font-mono">{{ $item->product?->sku }}</td>
                                                        <td class="px-3 py-2 text-right">{{ $quantity($item->quantity) }}</td>
                                                        <td class="px-3 py-2">{{ $item->product?->unit?->short_name }}</td>
                                                        @if ($canViewProfit)
                                                            <td class="px-3 py-2 text-right">{{ $money($item->unit_cost) }}</td>
                                                        @endif
                                                        <td class="px-3 py-2 text-right">{{ $money($item->unit_price) }}</td>
                                                        @if ($canViewProfit)
                                                            <td class="px-3 py-2 text-right">{{ $money($lineCost($item)) }}</td>
                                                        @endif
                                                        <td class="px-3 py-2 text-right font-bold">{{ $money($item->line_total) }}</td>
                                                        @if ($canViewProfit)
                                                            <td class="px-3 py-2 text-right font-bold">{{ $money($lineProfit($item)) }}</td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                    @empty
                        <tr><td colspan="{{ $canViewProfit ? 12 : 10 }}" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t('no_sales_found') }}</td></tr>
                    @endforelse
                </x-table>
                <div class="mt-4">{{ $invoices->links() }}</div>
            </x-card>
        @else
            @php
                $groupRows = match ($view) {
                    'product' => $summaryItems->groupBy('product_id')->map(fn ($items) => [
                        'label' => $items->first()->product?->displayNameWithSize(),
                        'sub' => $items->first()->product?->sku,
                        'quantity' => $items->sum('quantity'),
                        'cost' => $items->sum(fn ($item) => $lineCost($item)),
                        'sales' => $items->sum('line_total'),
                        'profit' => $items->sum(fn ($item) => $lineProfit($item)),
                        'retail_quantity' => $items->where('sale_type', 'retail')->sum('quantity'),
                        'wholesale_quantity' => $items->where('sale_type', 'wholesale')->sum('quantity'),
                    ])->values(),
                    'customer' => $summaryItems->groupBy(fn ($item) => $item->sale?->customer_id ?: 'walk-in')->map(fn ($items) => [
                        'label' => $customerLabel($items->first()->sale?->customer),
                        'sub' => number_format($items->pluck('sale_id')->unique()->count()).' '.$t('sales_count'),
                        'quantity' => $items->sum('quantity'),
                        'cost' => $items->sum(fn ($item) => $lineCost($item)),
                        'sales' => $items->sum('line_total'),
                        'profit' => $items->sum(fn ($item) => $lineProfit($item)),
                        'retail_quantity' => $items->where('sale_type', 'retail')->sum('quantity'),
                        'wholesale_quantity' => $items->where('sale_type', 'wholesale')->sum('quantity'),
                    ])->values(),
                    'cashier' => $summaryItems->groupBy(fn ($item) => $item->sale?->sold_by ?: $item->sale?->created_by ?: 'unknown')->map(fn ($items) => [
                        'label' => $cashierLabel($items->first()->sale),
                        'sub' => number_format($items->pluck('sale_id')->unique()->count()).' '.$t('sales_count'),
                        'quantity' => $items->sum('quantity'),
                        'cost' => $items->sum(fn ($item) => $lineCost($item)),
                        'sales' => $items->sum('line_total'),
                        'profit' => $items->sum(fn ($item) => $lineProfit($item)),
                        'retail_quantity' => $items->where('sale_type', 'retail')->sum('quantity'),
                        'wholesale_quantity' => $items->where('sale_type', 'wholesale')->sum('quantity'),
                    ])->values(),
                    default => $summaryItems->groupBy('stock_location_id')->map(fn ($items) => [
                        'label' => $items->first()->sold_from_label ?: ($items->first()->stockLocation ? InventorySettings::stockLocationLabel($items->first()->stockLocation) : '-'),
                        'sub' => number_format($items->pluck('sale_id')->unique()->count()).' '.$t('sales_count'),
                        'quantity' => $items->sum('quantity'),
                        'cost' => $items->sum(fn ($item) => $lineCost($item)),
                        'sales' => $items->sum('line_total'),
                        'profit' => $items->sum(fn ($item) => $lineProfit($item)),
                        'retail_quantity' => $items->where('sale_type', 'retail')->sum('quantity'),
                        'wholesale_quantity' => $items->where('sale_type', 'wholesale')->sum('quantity'),
                    ])->values(),
                };
                $page = LengthAwarePaginator::resolveCurrentPage();
                $groups = new LengthAwarePaginator($groupRows->forPage($page, 25)->values(), $groupRows->count(), 25, $page, ['path' => request()->url(), 'query' => request()->query()]);
            @endphp
            <x-card class="mt-6">
                <x-table :headers="$canViewProfit ? [$t('group'), $t('total_quantity_sold'), $t('total_cost'), $t('total_sales'), $t('total_profit'), $t('retail_quantity'), $t('wholesale_quantity')] : [$t('group'), $t('total_quantity_sold'), $t('total_sales'), $t('retail_quantity'), $t('wholesale_quantity')]">
                    @forelse ($groups as $row)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-4 py-3"><p class="font-black">{{ $row['label'] }}</p><p class="text-xs text-slate-500">{{ $row['sub'] }}</p></td>
                            <td class="px-4 py-3 text-right">{{ $quantity($row['quantity']) }}</td>
                            @if ($canViewProfit)
                                <td class="px-4 py-3 text-right">{{ $money($row['cost']) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right font-bold">{{ $money($row['sales']) }}</td>
                            @if ($canViewProfit)
                                <td class="px-4 py-3 text-right font-bold">{{ $money($row['profit']) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right">{{ $quantity($row['retail_quantity']) }}</td>
                            <td class="px-4 py-3 text-right">{{ $quantity($row['wholesale_quantity']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canViewProfit ? 7 : 5 }}" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t('no_sales_found') }}</td></tr>
                    @endforelse
                </x-table>
                <div class="mt-4">{{ $groups->links() }}</div>
            </x-card>
        @endif
    @else
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
                ->when($branch_id, fn ($query) => $query->where('branch_id', $branch_id))
                ->when($date_from, fn ($query) => $query->whereDate('sale_date', '>=', $date_from))
                ->when($date_to, fn ($query) => $query->whereDate('sale_date', '<=', $date_to))
                ->latest()
                ->paginate(12);
        @endphp

        <x-card class="mt-6">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">{{ $t('sale') }}</th>
                        <th class="px-4 py-3 text-left">{{ $t('customer') }}</th>
                        <th class="px-4 py-3 text-left">{{ $t('sale_type') }}</th>
                        <th class="px-4 py-3 text-left">{{ $t('stock_location') }}</th>
                        <th class="px-4 py-3 text-left">{{ $t('cashier') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('total') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('paid') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('balance') }}</th>
                        <th class="px-4 py-3 text-left">{{ $t('status') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('actions') }}</th>
                    </tr>
                </x-slot:head>
                @forelse ($sales as $sale)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3"><p class="font-bold">{{ $sale->sale_number }}</p><p class="text-xs text-slate-500">{{ $sale->sale_date?->format('M d, Y') }}</p></td>
                        <td class="px-4 py-3">{{ $customerLabel($sale->customer) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->saleType() === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300' }}">{{ $saleTypeLabel($sale->saleType()) }}</span></td>
                        <td class="px-4 py-3">{{ $sale->stockLocationLabel() }}</td>
                        <td class="px-4 py-3">{{ $cashierLabel($sale) }}</td>
                        <td class="px-4 py-3 text-right font-bold">{{ $money($sale->total_amount) }}</td>
                        <td class="px-4 py-3 text-right">{{ $money($sale->paid_amount) }}</td>
                        <td class="px-4 py-3 text-right">{{ $money($sale->balance_amount) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300' }}">{{ ucfirst($sale->status) }}</span><span class="ml-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ ucfirst($sale->payment_status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <a href="{{ route('sales.show', $sale) }}" wire:navigate class="text-sm font-bold text-navy-700 dark:text-white">{{ $t('view_action') }}</a>
                                <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="text-sm font-bold text-build-orange">{{ $t('receipt') }}</a>
                                @if ($sale->status === 'completed' && (float) $sale->balance_amount > 0)
                                    <a href="{{ route('sales.payments', $sale) }}" wire:navigate class="text-sm font-bold text-emerald-700 dark:text-emerald-300">{{ $t('payment') }}</a>
                                @endif
                                @role('Super Admin|Admin')
                                    @if ($sale->status === 'completed')
                                        <a href="{{ route('sales.cancel', $sale) }}" wire:navigate class="text-sm font-bold text-red-600">{{ $t('cancel') }}</a>
                                    @endif
                                @endrole
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t('no_sales_found') }}</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4">{{ $sales->links() }}</div>
        </x-card>
    @endif
</div>
