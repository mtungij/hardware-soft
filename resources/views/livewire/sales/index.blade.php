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
        $money = fn ($value): string => 'TZS '.number_format((float) $value, 2);
        $canViewProfit = auth()->user()?->can('view sales profit') ?? false;
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
                ->with(['sale.customer', 'sale.soldBy', 'sale.createdBy', 'sale.payments', 'sale.branch', 'product.unit', 'product.category', 'stockLocation'])
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

    <x-page-header :title="$isItemView ? $t('daily_sales_product_details') : $t('sales')" :description="$isItemView ? $t('daily_sales_description') : $t('sales_description')" :breadcrumbs="['Dashboard' => route('dashboard'), $t('sales') => null]">
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
                <option value="items">{{ $t('item_details') }}</option>
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
                @foreach (Product::orderBy('name')->get() as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} / {{ $product->sku }}</option>
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
            $filteredSaleIds = $summaryItems->pluck('sale_id')->unique()->values();
            $summary = [
                ['label' => $t('today_sales_total'), 'value' => $money($summaryItems->sum('line_total')), 'tone' => 'text-emerald-600'],
                ...($canViewProfit ? [
                    ['label' => $t('goods_cost_total'), 'value' => $money($summaryItems->sum(fn ($item) => $lineCost($item))), 'tone' => 'text-red-600'],
                    ['label' => $t('today_profit'), 'value' => $money($summaryItems->sum(fn ($item) => $lineProfit($item))), 'tone' => 'text-cyan-600'],
                ] : []),
                ['label' => $t('sold_quantity_total'), 'value' => number_format($summaryItems->sum('quantity'), 2), 'tone' => 'text-navy-900 dark:text-white'],
                ['label' => $t('sales_count'), 'value' => number_format($filteredSaleIds->count()), 'tone' => 'text-navy-900 dark:text-white'],
                ['label' => $t('retail_sales_total'), 'value' => $money($summaryItems->where('sale_type', 'retail')->sum('line_total')), 'tone' => 'text-cyan-600'],
                ['label' => $t('wholesale_sales_total'), 'value' => $money($summaryItems->where('sale_type', 'wholesale')->sum('line_total')), 'tone' => 'text-emerald-600'],
                ['label' => $t('cash_sales_total'), 'value' => $money(SalePayment::whereIn('sale_id', $filteredSaleIds)->where('payment_method', 'cash')->sum('amount')), 'tone' => 'text-emerald-600'],
                ['label' => $t('credit_sales_total'), 'value' => $money(SalePayment::whereIn('sale_id', $filteredSaleIds)->where('payment_method', 'credit')->sum('amount')), 'tone' => 'text-amber-600'],
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
            @php $saleItems = $itemQuery()->latest('id')->paginate(25); @endphp
            <x-card class="mt-6">
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="px-3 py-3 text-left">{{ $t('sale_number') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('sale_time') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('product_name') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('sku') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('sale_type') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('customer') }}</th>
                            <th class="px-3 py-3 text-right">{{ $t('quantity_sold') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('unit') }}</th>
                            @if ($canViewProfit)
                                <th class="px-3 py-3 text-right">{{ $t('buying_price_unit') }}</th>
                                <th class="px-3 py-3 text-right">{{ $t('total_cost') }}</th>
                            @endif
                            <th class="px-3 py-3 text-right">{{ $t('selling_price_unit') }}</th>
                            <th class="px-3 py-3 text-right">{{ $t('total_sales') }}</th>
                            <th class="px-3 py-3 text-right">{{ $t('discount') }}</th>
                            @if ($canViewProfit)
                                <th class="px-3 py-3 text-right">{{ $t('profit') }}</th>
                            @endif
                            <th class="px-3 py-3 text-left">{{ $t('cashier') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('stock_location') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('payment_status') }}</th>
                            <th class="px-3 py-3 text-left">{{ $t('payment_method') }}</th>
                        </tr>
                    </x-slot:head>
                    @forelse ($saleItems as $item)
                        @php
                            $methods = $item->sale?->payments?->pluck('payment_method')->filter()->unique()->map(fn ($method) => $paymentLabel($method))->join(', ') ?: '-';
                        @endphp
                        <tr class="border-t border-slate-100 text-xs dark:border-slate-800">
                            <td class="px-3 py-2 font-bold">{{ $item->sale?->sale_number }}</td>
                            <td class="px-3 py-2">{{ $item->sale?->created_at?->format('H:i') }}</td>
                            <td class="px-3 py-2 font-bold">{{ $item->product?->name }}</td>
                            <td class="px-3 py-2 font-mono">{{ $item->product?->sku }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $item->sale_type === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300' }}">{{ $saleTypeLabel($item->sale_type) }}</span></td>
                            <td class="px-3 py-2">{{ $customerLabel($item->sale?->customer) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                            <td class="px-3 py-2">{{ $item->product?->unit?->short_name }}</td>
                            @if ($canViewProfit)
                                <td class="px-3 py-2 text-right">{{ $money($item->unit_cost) }}</td>
                                <td class="px-3 py-2 text-right">{{ $money($lineCost($item)) }}</td>
                            @endif
                            <td class="px-3 py-2 text-right">{{ $money($item->unit_price) }}</td>
                            <td class="px-3 py-2 text-right font-bold">{{ $money($item->line_total) }}</td>
                            <td class="px-3 py-2 text-right">{{ $money($item->discount_amount) }}</td>
                            @if ($canViewProfit)
                                <td class="px-3 py-2 text-right font-bold {{ $lineProfit($item) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $money($lineProfit($item)) }}</td>
                            @endif
                            <td class="px-3 py-2">{{ $cashierLabel($item->sale) }}</td>
                            <td class="px-3 py-2">{{ $item->sold_from_label ?: ($item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : '-') }}</td>
                            <td class="px-3 py-2">{{ str($item->sale?->payment_status)->title() }}</td>
                            <td class="px-3 py-2">{{ $methods }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canViewProfit ? 18 : 15 }}" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t('no_sales_found') }}</td></tr>
                    @endforelse
                </x-table>
                <div class="mt-4">{{ $saleItems->links() }}</div>
            </x-card>
        @else
            @php
                $groupRows = match ($view) {
                    'product' => $summaryItems->groupBy('product_id')->map(fn ($items) => [
                        'label' => $items->first()->product?->name,
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
                            <td class="px-4 py-3 text-right">{{ number_format($row['quantity'], 2) }}</td>
                            @if ($canViewProfit)
                                <td class="px-4 py-3 text-right">{{ $money($row['cost']) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right font-bold">{{ $money($row['sales']) }}</td>
                            @if ($canViewProfit)
                                <td class="px-4 py-3 text-right font-bold">{{ $money($row['profit']) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right">{{ number_format($row['retail_quantity'], 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row['wholesale_quantity'], 2) }}</td>
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
