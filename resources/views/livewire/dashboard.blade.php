<?php

use App\Models\Branch;
use App\Models\Announcement;
use App\Models\Category;
use App\Models\CustomerNotification;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'dateFilter' => 'this_month',
    'branchFilter' => '',
    'customFrom' => '',
    'customTo' => '',
]);

$dateRange = function (): array {
    return match ($this->dateFilter) {
        'today' => [today()->startOfDay(), today()->endOfDay()],
        'this_week' => [now()->startOfWeek()->startOfDay(), now()->endOfWeek()->endOfDay()],
        'this_year' => [now()->startOfYear()->startOfDay(), now()->endOfYear()->endOfDay()],
        'custom' => [
            $this->customFrom ? \Carbon\Carbon::parse($this->customFrom)->startOfDay() : now()->startOfMonth()->startOfDay(),
            $this->customTo ? \Carbon\Carbon::parse($this->customTo)->endOfDay() : today()->endOfDay(),
        ],
        default => [now()->startOfMonth()->startOfDay(), today()->endOfDay()],
    };
};

$canViewAllBranches = fn (): bool => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Accountant']);

$activeBranchId = function (): ?int {
    if ($this->canViewAllBranches()) {
        return $this->branchFilter ? (int) $this->branchFilter : null;
    }

    return auth()->user()->branch_id ? (int) auth()->user()->branch_id : null;
};

$completedSalesQuery = function () {
    [$from, $to] = $this->dateRange();

    return Sale::query()
        ->where('status', 'completed')
        ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId));
};

$purchaseQuery = function () {
    [$from, $to] = $this->dateRange();

    return Purchase::query()
        ->where('status', '!=', 'cancelled')
        ->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId));
};

$expenseQuery = function () {
    [$from, $to] = $this->dateRange();

    return Expense::query()
        ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId));
};

$stockQuantityFor = function (int $productId, ?int $locationId = null): float {
    return (float) StockMovement::query()
        ->where('product_id', $productId)
        ->when($locationId, fn ($query) => $query->where('stock_location_id', $locationId))
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->get()
        ->sum(fn (StockMovement $movement) => $movement->signedQuantity());
};

$averageCostFor = function (int $productId, ?int $locationId = null): float {
    $incoming = StockMovement::query()
        ->where('product_id', $productId)
        ->whereIn('movement_type', StockMovement::POSITIVE_TYPES)
        ->whereNotNull('unit_cost')
        ->when($locationId, fn ($query) => $query->where('stock_location_id', $locationId))
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->get();

    $quantity = (float) $incoming->sum('quantity');

    return $quantity > 0
        ? (float) round($incoming->sum(fn (StockMovement $movement) => (float) $movement->quantity * (float) $movement->unit_cost) / $quantity, 2)
        : 0.0;
};

$stockValueByLocationType = function (string $type): float {
    $locations = StockLocation::query()
        ->where('type', $type)
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->pluck('id');

    if ($locations->isEmpty()) {
        return 0.0;
    }

    return (float) Product::query()
        ->get()
        ->sum(function (Product $product) use ($locations) {
            return $locations->sum(function (int $locationId) use ($product) {
                $quantity = $this->stockQuantityFor($product->id, $locationId);

                return $quantity * $this->averageCostFor($product->id, $locationId);
            });
        });
};

$stockItemsByLocationType = function (string $type): int {
    $locations = StockLocation::query()
        ->where('type', $type)
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->pluck('id');

    return Product::query()
        ->get()
        ->filter(fn (Product $product) => $locations->sum(fn (int $locationId) => $this->stockQuantityFor($product->id, $locationId)) > 0)
        ->count();
};

$branchOptions = computed(function () {
    return $this->canViewAllBranches()
        ? Branch::query()->where('status', 'active')->orderBy('name')->get()
        : Branch::query()->whereKey(auth()->user()->branch_id)->get();
});

$todaySales = computed(function (): float {
    return (float) Sale::query()
        ->where('status', 'completed')
        ->whereDate('sale_date', today())
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->sum('total_amount');
});

$monthlySales = computed(function (): float {
    return (float) Sale::query()
        ->where('status', 'completed')
        ->whereMonth('sale_date', now()->month)
        ->whereYear('sale_date', now()->year)
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->sum('total_amount');
});

$totalProfit = computed(function (): float {
    return (float) SaleItem::query()
        ->whereHas('sale', fn ($query) => $this->completedSalesQuery()->whereColumn('sales.id', 'sale_items.sale_id'))
        ->get()
        ->sum(fn (SaleItem $item) => (float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost));
});

$totalPurchases = computed(fn (): float => (float) $this->purchaseQuery()->sum('total_amount'));
$mainStoreStockValue = computed(fn (): float => $this->stockValueByLocationType('store'));
$dispensingStockValue = computed(fn (): float => $this->stockValueByLocationType('dispensing'));
$customerDebts = computed(fn (): float => (float) Sale::query()->where('status', 'completed')->whereIn('payment_status', ['unpaid', 'partial'])->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))->sum('balance_amount'));

$productStockRows = computed(function (): Collection {
    return Product::query()
        ->with('category')
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))
        ->orderBy('name')
        ->get()
        ->map(function (Product $product) {
            $stock = $this->stockQuantityFor($product->id);
            $locations = StockLocation::query()
                ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->get()
                ->map(fn (StockLocation $location) => [
                    'name' => $location->name,
                    'quantity' => $this->stockQuantityFor($product->id, $location->id),
                ])
                ->filter(fn (array $row) => $row['quantity'] > 0)
                ->values();

            return [
                'product' => $product,
                'current_stock' => $stock,
                'locations' => $locations,
            ];
        });
});

$lowStockProducts = computed(fn (): Collection => $this->productStockRows->filter(fn (array $row) => $row['current_stock'] <= (float) $row['product']->reorder_level)->values());
$lowStockAlerts = computed(fn (): int => $this->lowStockProducts->count());

$inventorySummary = computed(function (): array {
    $rows = $this->productStockRows;

    return [
        'total_products' => Product::query()->when($this->activeBranchId(), fn ($query, $branchId) => $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))->count(),
        'active_products' => Product::query()->where('status', 'active')->when($this->activeBranchId(), fn ($query, $branchId) => $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))->count(),
        'out_of_stock_products' => $rows->filter(fn (array $row) => $row['current_stock'] <= 0)->count(),
        'low_stock_products' => $this->lowStockAlerts,
        'main_store_stock_items' => $this->stockItemsByLocationType('store'),
        'dispensing_stock_items' => $this->stockItemsByLocationType('dispensing'),
    ];
});

$salesTrendChart = computed(function (): Collection {
    $period = collect(CarbonPeriod::create(today()->subDays(6), today()))
        ->mapWithKeys(fn ($date) => [$date->toDateString() => ['date' => $date->format('M d'), 'sales' => 0.0, 'profit' => 0.0]]);

    $sales = Sale::query()
        ->where('status', 'completed')
        ->whereBetween('sale_date', [today()->subDays(6)->toDateString(), today()->toDateString()])
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->with('items')
        ->get();

    foreach ($sales as $sale) {
        $key = $sale->sale_date->toDateString();

        if (! $period->has($key)) {
            continue;
        }

        $row = $period->get($key);
        $row['sales'] += (float) $sale->total_amount;
        $row['profit'] += $sale->items->sum(fn (SaleItem $item) => (float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost));

        $period->put($key, $row);
    }

    return $period->values();
});

$salesByCategoryChart = computed(function (): Collection {
    return SaleItem::query()
        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
        ->join('products', 'products.id', '=', 'sale_items.product_id')
        ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
        ->where('sales.status', 'completed')
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('sales.branch_id', $branchId))
        ->selectRaw("coalesce(categories.name, 'Uncategorized') as category_name, sum(sale_items.line_total) as total_sales")
        ->groupBy('category_name')
        ->orderByDesc('total_sales')
        ->limit(8)
        ->get();
});

$stockDistributionChart = computed(function (): Collection {
    return StockLocation::query()
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
        ->orderBy('type')
        ->get()
        ->map(fn (StockLocation $location) => [
            'name' => $location->name,
            'type' => $location->type,
            'quantity' => (float) StockMovement::query()
                ->where('stock_location_id', $location->id)
                ->get()
                ->sum(fn (StockMovement $movement) => $movement->signedQuantity()),
        ]);
});

$monthlyRevenueExpenseChart = computed(function (): Collection {
    return collect(range(5, 0))
        ->map(function (int $monthsBack) {
            $month = now()->subMonths($monthsBack);

            $sales = Sale::query()
                ->where('status', 'completed')
                ->whereMonth('sale_date', $month->month)
                ->whereYear('sale_date', $month->year)
                ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->sum('total_amount');

            $purchases = Purchase::query()
                ->where('status', '!=', 'cancelled')
                ->whereMonth('purchase_date', $month->month)
                ->whereYear('purchase_date', $month->year)
                ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->sum('total_amount');

            $expenses = Expense::query()
                ->whereMonth('expense_date', $month->month)
                ->whereYear('expense_date', $month->year)
                ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->sum('amount');

            return ['month' => $month->format('M'), 'sales' => (float) $sales, 'purchases' => (float) $purchases, 'expenses' => (float) $expenses];
        });
});

$topSellingProducts = computed(function (): Collection {
    return SaleItem::query()
        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
        ->where('sales.status', 'completed')
        ->when($this->activeBranchId(), fn ($query, $branchId) => $query->where('sales.branch_id', $branchId))
        ->selectRaw('sale_items.product_id, sum(sale_items.quantity) as quantity_sold, sum(sale_items.line_total) as total_sales, sum(sale_items.line_total - (sale_items.quantity * sale_items.unit_cost)) as profit_amount')
        ->groupBy('sale_items.product_id')
        ->orderByDesc('quantity_sold')
        ->with('product')
        ->limit(6)
        ->get();
});

$recentTransactions = computed(function (): Collection {
    [$from, $to] = $this->dateRange();
    $branchId = $this->activeBranchId();

    $sales = Sale::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Sale $sale) => [
        'type' => 'Sale',
        'reference' => $sale->sale_number,
        'amount' => (float) $sale->total_amount,
        'status' => $sale->status,
        'date' => $sale->created_at,
        'route' => route('sales.show', $sale),
    ]);

    $purchases = Purchase::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Purchase $purchase) => [
        'type' => 'Purchase',
        'reference' => $purchase->reference_number,
        'amount' => (float) $purchase->total_amount,
        'status' => $purchase->status,
        'date' => $purchase->created_at,
        'route' => route('purchases.show', $purchase),
    ]);

    $transfers = StockTransfer::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (StockTransfer $transfer) => [
        'type' => 'Transfer',
        'reference' => $transfer->transfer_number,
        'amount' => null,
        'status' => $transfer->status,
        'date' => $transfer->created_at,
        'route' => route('stock-transfers.show', $transfer),
    ]);

    $expenses = Expense::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Expense $expense) => [
        'type' => 'Expense',
        'reference' => $expense->reference_number ?: $expense->category?->name,
        'amount' => (float) $expense->amount,
        'status' => $expense->payment_method,
        'date' => $expense->created_at,
        'route' => route('expenses.index'),
    ]);

    $customerPayments = CustomerPayment::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (CustomerPayment $payment) => [
        'type' => 'Customer Payment',
        'reference' => $payment->reference_number ?: $payment->customer?->name,
        'amount' => (float) $payment->amount,
        'status' => $payment->payment_method,
        'date' => $payment->created_at,
        'route' => route('customer-balances.show', $payment->customer_id),
    ]);

    return $sales
        ->merge($purchases)
        ->merge($transfers)
        ->merge($expenses)
        ->merge($customerPayments)
        ->filter(fn (array $row) => $row['date']->between($from, $to) || $this->dateFilter === 'today')
        ->sortByDesc('date')
        ->take(12)
        ->values();
});

?>

<div data-tour="dashboard-overview" class="max-w-full min-w-0 overflow-x-hidden space-y-6">
    @php
        $currency = 'TZS';
        $formatMoney = fn ($value) => $currency.' '.number_format((float) $value, 2);
        $maxTrend = max(1, $this->salesTrendChart->max('sales') ?: 1);
        $maxCategory = max(1, $this->salesByCategoryChart->max('total_sales') ?: 1);
        $maxStock = max(1, $this->stockDistributionChart->max('quantity') ?: 1);
        $maxMonthly = max(1, $this->monthlyRevenueExpenseChart->map(fn ($row) => max($row['sales'], $row['purchases'], $row['expenses']))->max() ?: 1);
        $cards = [
            ['label' => "Today's Sales", 'value' => $formatMoney($this->todaySales), 'tone' => 'text-emerald-600', 'hint' => 'Completed sales today'],
            ['label' => 'Monthly Sales', 'value' => $formatMoney($this->monthlySales), 'tone' => 'text-navy-900 dark:text-white', 'hint' => now()->format('F Y')],
            ['label' => 'Total Profit', 'value' => $formatMoney($this->totalProfit), 'tone' => 'text-emerald-600', 'hint' => 'Filtered completed sales'],
            ['label' => 'Total Purchases', 'value' => $formatMoney($this->totalPurchases), 'tone' => 'text-navy-900 dark:text-white', 'hint' => 'Non-cancelled purchases'],
            ['label' => 'Main Store Stock Value', 'value' => $formatMoney($this->mainStoreStockValue), 'tone' => 'text-navy-900 dark:text-white', 'hint' => 'Warehouse valuation'],
            ['label' => 'Dispensing Stock Value', 'value' => $formatMoney($this->dispensingStockValue), 'tone' => 'text-navy-900 dark:text-white', 'hint' => 'Sales counter valuation'],
            ['label' => 'Customer Debts', 'value' => $formatMoney($this->customerDebts), 'tone' => 'text-red-600', 'hint' => 'Unpaid and partial sales'],
            ['label' => 'Low Stock Alerts', 'value' => number_format($this->lowStockAlerts), 'tone' => 'text-amber-600', 'hint' => 'At or below reorder level'],
            ['label' => 'Purchase Orders Sent Today', 'value' => number_format(PurchaseEmailLog::where('status', 'sent')->whereDate('sent_at', today())->count()), 'tone' => 'text-emerald-600', 'hint' => 'Supplier PO emails delivered'],
            ['label' => 'Failed Purchase Emails', 'value' => number_format(PurchaseEmailLog::where('status', 'failed')->count()), 'tone' => 'text-red-600', 'hint' => 'Needs SMTP or recipient review'],
            ['label' => 'Pending Purchase Emails', 'value' => number_format(PurchaseEmailLog::where('status', 'pending')->count()), 'tone' => 'text-amber-600', 'hint' => 'Queued and waiting for workers'],
            ['label' => 'Announcements Sent', 'value' => number_format(CustomerNotification::where('type', 'announcement')->count()), 'tone' => 'text-cyan-600', 'hint' => 'Portal announcement deliveries'],
            ['label' => 'Unread Customer Messages', 'value' => number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNull('read_at')->count()), 'tone' => 'text-amber-600', 'hint' => 'Waiting for customer read'],
            ['label' => 'Customers Reached', 'value' => number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->distinct('customer_id')->count('customer_id')), 'tone' => 'text-emerald-600', 'hint' => 'Unique customers notified'],
        ];
        $recentAnnouncements = Announcement::query()->latest()->limit(5)->get();
    @endphp

    <x-page-header
        title="Hardex POS"
        description="Business dashboard for sales, inventory, cash flow, and operational alerts."
        :breadcrumbs="['Dashboard' => null]"
    >
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pos.index') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Open POS</a>
            <a href="{{ route('reports.profit-loss') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Profit Report</a>
        </div>
    </x-page-header>

    <x-card>
        <div class="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <label class="block text-sm font-bold">
                Date Filter
                <select wire:model.live="dateFilter" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="today">Today</option>
                    <option value="this_week">This Week</option>
                    <option value="this_month">This Month</option>
                    <option value="this_year">This Year</option>
                    <option value="custom">Custom Range</option>
                </select>
            </label>
            <label class="block text-sm font-bold">
                Branch
                <select wire:model.live="branchFilter" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(! $this->canViewAllBranches())>
                    @if ($this->canViewAllBranches())
                        <option value="">All branches</option>
                    @endif
                    @foreach ($this->branchOptions as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold">
                From
                <input wire:model.live="customFrom" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-navy-950" @disabled($dateFilter !== 'custom')>
            </label>
            <label class="block text-sm font-bold">
                To
                <input wire:model.live="customTo" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-navy-950" @disabled($dateFilter !== 'custom')>
            </label>
            <div class="flex items-end">
                <div wire:loading class="rounded-lg bg-orange-50 px-3 py-2 text-sm font-bold text-build-orange dark:bg-orange-500/10">Refreshing...</div>
            </div>
        </div>
    </x-card>

    <div wire:loading.delay class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (range(1, 8) as $item)
            <div class="h-32 animate-pulse rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-navy-900">
                <div class="h-3 w-28 rounded bg-slate-200 dark:bg-slate-700"></div>
                <div class="mt-5 h-7 w-40 rounded bg-slate-200 dark:bg-slate-700"></div>
                <div class="mt-4 h-3 w-24 rounded bg-slate-200 dark:bg-slate-700"></div>
            </div>
        @endforeach
    </div>

    <div wire:loading.remove.delay data-tour="dashboard-stats" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                        <p class="mt-3 text-2xl font-black {{ $card['tone'] }}">{{ $card['value'] }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ $card['hint'] }}</p>
                    </div>
                    <span class="grid h-10 w-10 place-items-center rounded-lg bg-orange-50 text-sm font-black text-build-orange dark:bg-orange-500/10">{{ collect(explode(' ', $card['label']))->map(fn ($word) => $word[0])->take(2)->join('') }}</span>
                </div>
            </x-card>
        @endforeach
    </div>

    <x-onboarding-checklist />

    <div data-tour="dashboard-charts" class="grid min-w-0 gap-6 xl:grid-cols-2">
        <x-card title="Recent Announcements" description="Latest customer notices and read progress.">
            <div class="space-y-3">
                @forelse ($recentAnnouncements as $announcement)
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">{{ $announcement->title }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ ucfirst($announcement->status) }} · {{ ucfirst($announcement->priority) }}</p>
                            </div>
                            <a href="{{ route('admin.announcements.index') }}" wire:navigate class="text-xs font-bold text-build-orange">Open</a>
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">No announcements yet.</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="Interactive Sales Trend" description="Chart.js view of sales and profit for the last 7 days.">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'line',
                            data: {
                                labels: @js($this->salesTrendChart->pluck('date')->values()),
                                datasets: [
                                    { label: 'Sales', data: @js($this->salesTrendChart->pluck('sales')->values()), borderColor: buildMartThemeColor(), backgroundColor: buildMartThemeColorAlpha(0.14), tension: 0.35, fill: true },
                                    { label: 'Profit', data: @js($this->salesTrendChart->pluck('profit')->values()), borderColor: '#059669', backgroundColor: 'rgba(5, 150, 105, 0.12)', tension: 0.35, fill: true }
                                ]
                            }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="Sales trend chart"></canvas>
            </div>
        </x-card>

        <x-card title="Revenue vs Expenses" description="Monthly revenue, purchases, and expenses from database records.">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: @js($this->monthlyRevenueExpenseChart->pluck('month')->values()),
                                datasets: [
                                    { label: 'Revenue', data: @js($this->monthlyRevenueExpenseChart->pluck('sales')->values()), backgroundColor: '#059669' },
                                    { label: 'Purchases', data: @js($this->monthlyRevenueExpenseChart->pluck('purchases')->values()), backgroundColor: '#0d2e50' },
                                    { label: 'Expenses', data: @js($this->monthlyRevenueExpenseChart->pluck('expenses')->values()), backgroundColor: '#ef4444' }
                                ]
                            }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="Monthly revenue versus expenses chart"></canvas>
            </div>
        </x-card>

        <x-card title="Category Performance" description="Sales amount grouped by product category.">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'doughnut',
                            data: {
                                labels: @js($this->salesByCategoryChart->pluck('category_name')->values()),
                                datasets: [{ label: 'Sales', data: @js($this->salesByCategoryChart->pluck('total_sales')->values()), backgroundColor: [buildMartThemeColor(), '#0d2e50', '#059669', '#f59e0b', '#3b82f6', '#ef4444', '#8b5cf6', '#14b8a6'] }]
                            },
                            options: { scales: { x: { display: false }, y: { display: false } } }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="Sales by category chart"></canvas>
            </div>
        </x-card>

        <x-card title="Stock Distribution" description="Current stock quantity grouped by stock location.">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: @js($this->stockDistributionChart->pluck('name')->values()),
                                datasets: [{ label: 'Quantity', data: @js($this->stockDistributionChart->pluck('quantity')->values()), backgroundColor: buildMartThemeColor() }]
                            },
                            options: { indexAxis: 'y' }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="Stock distribution chart"></canvas>
            </div>
        </x-card>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-2">
        <x-card title="Sales Trend" description="Completed sales total and profit for the last 7 days.">
            @if ($this->salesTrendChart->isEmpty())
                <p class="py-10 text-center text-sm text-slate-500">No sales trend data available.</p>
            @else
                <div class="space-y-4">
                    @foreach ($this->salesTrendChart as $row)
                        <div class="grid min-w-0 grid-cols-[64px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[72px_minmax(0,1fr)]">
                            <p class="text-xs font-bold text-slate-500">{{ $row['date'] }}</p>
                            <div>
                                <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-build-orange" style="width: {{ min(100, ($row['sales'] / $maxTrend) * 100) }}%"></div></div>
                                <p class="mt-1 text-xs text-slate-500">Sales {{ $formatMoney($row['sales']) }} / Profit {{ $formatMoney($row['profit']) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        <x-card title="Monthly Revenue vs Expenses" description="Sales revenue, purchases, and operating expenses by month.">
            <div class="space-y-4">
                @foreach ($this->monthlyRevenueExpenseChart as $row)
                    <div class="grid min-w-0 grid-cols-[44px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[48px_minmax(0,1fr)]">
                        <p class="text-xs font-bold text-slate-500">{{ $row['month'] }}</p>
                        <div class="space-y-1">
                            <div class="h-2 rounded bg-emerald-500" style="width: {{ min(100, ($row['sales'] / $maxMonthly) * 100) }}%"></div>
                            <div class="h-2 rounded bg-navy-700 dark:bg-slate-300" style="width: {{ min(100, ($row['purchases'] / $maxMonthly) * 100) }}%"></div>
                            <div class="h-2 rounded bg-red-500" style="width: {{ min(100, ($row['expenses'] / $maxMonthly) * 100) }}%"></div>
                            <p class="text-xs text-slate-500">Revenue {{ $formatMoney($row['sales']) }} / Purchases {{ $formatMoney($row['purchases']) }} / Expenses {{ $formatMoney($row['expenses']) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card title="Sales by Category" description="Category revenue from sold items.">
            @forelse ($this->salesByCategoryChart as $row)
                <div class="mb-4">
                    <div class="mb-1 flex min-w-0 justify-between gap-3 text-sm"><span class="min-w-0 truncate font-bold">{{ $row->category_name }}</span><span class="shrink-0">{{ $formatMoney($row->total_sales) }}</span></div>
                    <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-build-orange" style="width: {{ min(100, ((float) $row->total_sales / $maxCategory) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-slate-500">No category sales found.</p>
            @endforelse
        </x-card>

        <x-card title="Stock Distribution" description="Current stock quantities by location.">
            @forelse ($this->stockDistributionChart as $row)
                <div class="mb-4">
                    <div class="mb-1 flex min-w-0 justify-between gap-3 text-sm"><span class="min-w-0 truncate font-bold">{{ $row['name'] }} <span class="text-xs font-semibold text-slate-500">({{ ucfirst($row['type']) }})</span></span><span class="shrink-0">{{ number_format($row['quantity'], 2) }}</span></div>
                    <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-navy-800 dark:bg-build-orange" style="width: {{ min(100, ($row['quantity'] / $maxStock) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-slate-500">No stock movement data found.</p>
            @endforelse
        </x-card>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-card title="Inventory Summary">
                <div class="space-y-3">
                    @foreach ([
                        'Total Products' => $this->inventorySummary['total_products'],
                        'Active Products' => $this->inventorySummary['active_products'],
                        'Out of Stock Products' => $this->inventorySummary['out_of_stock_products'],
                        'Low Stock Products' => $this->inventorySummary['low_stock_products'],
                        'Main Store Stock Items' => $this->inventorySummary['main_store_stock_items'],
                        'Dispensing Stock Items' => $this->inventorySummary['dispensing_stock_items'],
                    ] as $label => $value)
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5">
                            <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ $label }}</span>
                            <span class="font-black">{{ number_format($value) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card title="System Snapshot">
                @foreach ([
                    'Total Users' => User::count(),
                    'Active Branches' => Branch::where('status', 'active')->count(),
                    'Active Categories' => Category::where('status', 'active')->count(),
                ] as $label => $value)
                    <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5">
                        <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ $label }}</span>
                        <span class="font-black">{{ number_format($value) }}</span>
                    </div>
                @endforeach
            </x-card>
        </div>

        <x-card title="Low Stock Alerts" description="Products where current total stock is at or below reorder level.">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">Product</th>
                        <th class="px-4 py-3 text-left">SKU</th>
                        <th class="px-4 py-3 text-left">Category</th>
                        <th class="px-4 py-3 text-right">Current</th>
                        <th class="px-4 py-3 text-right">Reorder</th>
                        <th class="px-4 py-3 text-left">Location</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </x-slot:head>
                @forelse ($this->lowStockProducts->take(8) as $row)
                    @php $product = $row['product']; @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img class="h-10 w-10 rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                                <span class="font-bold">{{ $product->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $product->sku }}</td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? 'Uncategorized' }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($row['current_stock'], 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format((float) $product->reorder_level, 2) }}</td>
                        <td class="px-4 py-3">{{ $row['locations']->isEmpty() ? 'No stock location' : $row['locations']->map(fn ($location) => $location['name'].' ('.number_format($location['quantity'], 2).')')->join(', ') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $row['current_stock'] <= 0 ? 'Out of Stock' : 'Low Stock' }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('products.index') }}" wire:navigate class="text-sm font-bold text-build-orange">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">No low stock products for the selected branch.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-2">
        <x-card title="Top Selling Products">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">Product</th>
                        <th class="px-4 py-3 text-left">SKU</th>
                        <th class="px-4 py-3 text-right">Qty Sold</th>
                        <th class="px-4 py-3 text-right">Sales</th>
                        <th class="px-4 py-3 text-right">Profit</th>
                    </tr>
                </x-slot:head>
                @forelse ($this->topSellingProducts as $row)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img class="h-10 w-10 rounded-lg object-cover" src="{{ $row->product?->image ? asset('storage/'.$row->product->image) : 'https://ui-avatars.com/api/?name='.urlencode($row->product?->name ?? 'Product').'&background=0d2e50&color=fff' }}" alt="{{ $row->product?->name }}">
                                <span class="font-bold">{{ $row->product?->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $row->product?->sku }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format((float) $row->quantity_sold, 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatMoney($row->total_sales) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-emerald-600">{{ $formatMoney($row->profit_amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">No completed sales yet.</td></tr>
                @endforelse
            </x-table>
        </x-card>

        <x-card title="Recent Transactions" description="Latest sales, purchases, transfers, expenses, and customer payments.">
            <div class="space-y-3">
                @forelse ($this->recentTransactions as $transaction)
                    <a href="{{ $transaction['route'] }}" wire:navigate class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-slate-100 p-3 transition hover:border-build-orange/40 hover:bg-orange-50/40 dark:border-slate-800 dark:hover:bg-orange-500/10">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $transaction['type'] }} · {{ $transaction['reference'] }}</p>
                            <p class="text-xs text-slate-500">{{ $transaction['date']->format('M d, Y H:i') }} · {{ str($transaction['status'])->replace('_', ' ')->title() }}</p>
                        </div>
                        <p class="shrink-0 text-sm font-black">{{ is_null($transaction['amount']) ? '-' : $formatMoney($transaction['amount']) }}</p>
                    </a>
                @empty
                    <p class="py-10 text-center text-sm text-slate-500">No recent transactions for the selected filters.</p>
                @endforelse
            </div>
        </x-card>
    </div>
</div>
