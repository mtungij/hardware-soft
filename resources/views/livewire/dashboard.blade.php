<?php

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Models\CustomerNotification;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\DashboardStockProjection;
use App\Services\FinancialReportService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'dateFilter' => 'this_month',
    'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'customFrom' => '',
    'customTo' => '',
]);

$dateRange = function (): array {
    return match ($this->dateFilter) {
        'today' => [today()->startOfDay(), today()->endOfDay()],
        'this_week' => [now()->startOfWeek()->startOfDay(), now()->endOfWeek()->endOfDay()],
        'this_year' => [now()->startOfYear()->startOfDay(), now()->endOfYear()->endOfDay()],
        'custom' => [
            $this->customFrom ? Carbon::parse($this->customFrom)->startOfDay() : now()->startOfMonth()->startOfDay(),
            $this->customTo ? Carbon::parse($this->customTo)->endOfDay() : today()->endOfDay(),
        ],
        default => [now()->startOfMonth()->startOfDay(), today()->endOfDay()],
    };
};

$hasBranches = computed(fn (): bool => Schema::hasTable('branches'));

$canViewAllBranches = computed(fn (): bool => $this->hasBranches
    && ! \App\Support\BranchAccess::restricted(auth()->user()));

$activeBranchId = computed(function (): ?int {
    if ($this->canViewAllBranches) {
        return $this->branchFilter ? (int) $this->branchFilter : null;
    }

    return auth()->user()->branch_id ? (int) auth()->user()->branch_id : null;
});

$dashboardSales = fn () => AuthorizationScope::sales(Sale::query(), auth()->user())
    ->where('sales.status', 'completed')
    ->when($this->activeBranchId, fn ($query, $id) => $query->where('sales.branch_id', $id));

$stockLocationIds = computed(fn () => AuthorizationScope::stockLocationIds(auth()->user()));

$stockLocations = computed(fn (): Collection => StockLocation::query()
    ->whereIn('id', $this->stockLocationIds)
    ->when($this->activeBranchId, fn ($query, $branchId) => $query->where('branch_id', $branchId))
    ->orderBy('type')
    ->get(['id', 'name', 'type']));

$stockMovements = fn () => StockMovement::query()->forDashboardAggregation()
    ->whereIn('stock_location_id', $this->stockLocationIds)
    ->when($this->activeBranchId, fn ($query, $branchId) => $query->where('branch_id', $branchId));

$projectionAvailable = computed(fn (): bool =>
    app(DashboardStockProjection::class)->available());

$branchOptions = computed(function () {
    if (! $this->hasBranches) {
        return collect();
    }

    return $this->canViewAllBranches
        ? Branch::query()->where('status', 'active')->orderBy('name')->get()
        : Branch::query()->whereKey(auth()->user()->branch_id)->get();
});

$salesHeaderTotals = computed(function (): array {
    if (! auth()->user()->can('dashboard.sales_summary')) {
        return [];
    }

    $today = today();
    $month = $today->copy()->startOfMonth();

    return (array) $this->dashboardSales()
        ->where('sales.sale_date', '>=', $month->toDateString())
        ->where('sales.sale_date', '<', $month->addMonth()->toDateString())
        ->selectRaw('SUM(sales.total_amount) AS monthly_sales,
            SUM(CASE WHEN sales.sale_date >= ? AND sales.sale_date < ? THEN sales.total_amount ELSE 0 END) AS today_sales',
            [$today->toDateString(), $today->copy()->addDay()->toDateString()])
        ->toBase()->first();
});

$todaySales = computed(fn (): float => (float) ($this->salesHeaderTotals['today_sales'] ?? 0));

$monthlySales = computed(fn (): float => (float) ($this->salesHeaderTotals['monthly_sales'] ?? 0));

$stockLocationValues = computed(fn (): Collection => auth()->user()->can('dashboard.stock_value')
    ? app(FinancialReportService::class)->stockValueByLocation($this->activeBranchId)
    : collect());
$customerDebts = computed(fn (): float => auth()->user()->can('dashboard.receivables')
    ? (float) AuthorizationScope::sales(Sale::query(), auth()->user())->where('status', 'completed')->whereIn('payment_status', ['unpaid', 'partial'])->when($this->activeBranchId, fn ($query, $branchId) => $query->where('branch_id', $branchId))->sum('balance_amount')
    : 0.0);

$stockOverview = computed(function (): Collection {
    if (! auth()->user()->can('dashboard.stock_summary')) {
        return collect();
    }

    $balancesByLocation = $this->projectionAvailable
        ? app(DashboardStockProjection::class)
            ->quantities($this->stockLocationIds, $this->activeBranchId)
        : $this->stockMovements()
            ->select('stock_location_id', 'product_id')->selectSignedQuantity()
            ->groupBy('stock_location_id', 'product_id')->toBase();
    $balances = $balancesByLocation->getConnection()->query()->fromSub($balancesByLocation, 'location_balances')
        ->select('product_id')->selectRaw('SUM(signed_quantity) AS signed_quantity')->groupBy('product_id');
    foreach (['store', 'dispensing'] as $type) {
        $locations = $this->stockLocations->where('type', $type)->modelKeys();
        if ($locations === []) {
            $balances->selectRaw("0 AS {$type}_quantity");
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($locations), '?'));
        $balances->selectRaw(
            "SUM(CASE WHEN stock_location_id IN ({$placeholders}) THEN signed_quantity ELSE 0 END) AS {$type}_quantity",
            $locations,
        );
    }

    // Location-type counts include all scoped products. The other cards use the selected catalog.
    $products = Product::query()
        ->leftJoinSub($balances, 'stock_balances', 'stock_balances.product_id', '=', 'products.id')
        ->select('products.id', 'products.name', 'products.status')
        ->selectRaw('COALESCE(stock_balances.signed_quantity, 0) AS current_stock, stock_balances.store_quantity, stock_balances.dispensing_quantity')
        ->selectRaw('CASE WHEN COALESCE(stock_balances.signed_quantity, 0) <= COALESCE(products.reorder_level, 0) THEN 1 ELSE 0 END AS low_stock');
    $branch = $this->activeBranchId;
    $branch ? $products->selectRaw('CASE WHEN products.branch_id IS NULL OR products.branch_id = ? THEN 1 ELSE 0 END AS in_catalog', [$branch])
        : $products->selectRaw('1 AS in_catalog');

    return $products->getConnection()->query()->fromSub($products->toBase(), 'stock')
        ->select('id', 'current_stock')
        ->selectRaw('in_catalog * low_stock AS low_stock_in_catalog,
            SUM(in_catalog) OVER () AS total_products,
            SUM(CASE WHEN status = ? THEN in_catalog ELSE 0 END) OVER () AS active_products,
            SUM(CASE WHEN current_stock <= 0 THEN in_catalog ELSE 0 END) OVER () AS out_of_stock_products,
            SUM(in_catalog * low_stock) OVER () AS low_stock_products,
            SUM(CASE WHEN store_quantity > 0 THEN 1 ELSE 0 END) OVER () AS main_store_stock_items,
            SUM(CASE WHEN dispensing_quantity > 0 THEN 1 ELSE 0 END) OVER () AS dispensing_stock_items', ['active'])
        ->orderByDesc('low_stock_in_catalog')->orderBy('name')->orderBy('id')->limit(8)->get();
});

$lowStockProducts = computed(function (): Collection {
    $stock = $this->stockOverview->where('low_stock_in_catalog', 1)->keyBy('id');
    if ($stock->isEmpty()) {
        return collect();
    }

    $products = Product::query()->whereKey($stock->keys()->all())->with('category')->get()->keyBy('id');
    $locations = $this->stockLocations->keyBy('id');
    $balances = $this->projectionAvailable
        ? app(DashboardStockProjection::class)
            ->quantities($locations->keys(), $this->activeBranchId)
            ->whereIn('product_id', $stock->keys())
            ->havingRaw('SUM(signed_quantity) > 0')
            ->get()->groupBy('product_id')
        : $this->stockMovements()
            ->whereIn('product_id', $stock->keys())->whereIn('stock_location_id', $locations->keys())
            ->select('product_id', 'stock_location_id')->selectSignedQuantity()
            ->groupBy('product_id', 'stock_location_id')->havingRaw('signed_quantity > 0')
            ->toBase()->get()->groupBy('product_id');

    return $stock->filter(fn ($row) => $products->has($row->id))->map(fn ($row) => [
        'product' => $products[$row->id],
        'current_stock' => (float) $row->current_stock,
        'locations' => $balances->get($row->id, collect())->map(fn ($balance) => [
            'name' => $locations[$balance->stock_location_id]->name,
            'quantity' => (float) $balance->signed_quantity,
        ]),
    ])->values();
});
$lowStockAlerts = computed(fn (): int => $this->inventorySummary['low_stock_products']);

$inventorySummary = computed(function (): array {
    $counts = $this->stockOverview->first();

    return [
        'total_products' => (int) ($counts->total_products ?? 0),
        'active_products' => (int) ($counts->active_products ?? 0),
        'out_of_stock_products' => (int) ($counts->out_of_stock_products ?? 0),
        'low_stock_products' => (int) ($counts->low_stock_products ?? 0),
        'main_store_stock_items' => (int) ($counts->main_store_stock_items ?? 0),
        'dispensing_stock_items' => (int) ($counts->dispensing_stock_items ?? 0),
    ];
});

$salesTrendChart = computed(function (): Collection {
    if (! auth()->user()->can('dashboard.sales_summary')) {
        return collect();
    }

    $period = collect(CarbonPeriod::create(today()->subDays(6), today()))
        ->mapWithKeys(fn ($date) => [$date->toDateString() => ['date' => $date->locale(app()->getLocale())->translatedFormat('M d'), 'sales' => 0.0, 'profit' => 0.0]]);

    $sales = $this->dashboardSales()
        ->whereBetween('sales.sale_date', [today()->subDays(6)->toDateString(), today()->toDateString()]);
    $totals = (clone $sales)->select('sale_date')->selectRaw('SUM(total_amount) AS total_amount')
        ->groupBy('sale_date')->toBase()->pluck('total_amount', 'sale_date');
    $profits = auth()->user()->can('dashboard.profit')
        ? SaleItem::query()->joinSub($sales->select('sales.id', 'sales.sale_date'), 'trend_sales', 'trend_sales.id', '=', 'sale_items.sale_id')
            ->select('trend_sales.sale_date')->selectRaw('SUM(sale_items.line_total - sale_items.quantity * COALESCE(sale_items.unit_cost, 0)) AS profit')
            ->groupBy('trend_sales.sale_date')->toBase()->pluck('profit', 'sale_date')
        : collect();

    $period = $period->map(fn (array $row, string $date) => [
        ...$row,
        'sales' => (float) $totals->get($date, 0),
        'profit' => (float) $profits->get($date, 0),
    ]);

    return $period->values();
});

$stockDistributionChart = computed(function (): Collection {
    if (! auth()->user()->can('dashboard.stock_summary')) {
        return collect();
    }

    $quantities = $this->projectionAvailable
        ? app(DashboardStockProjection::class)
            ->scopedBalances($this->stockLocations->modelKeys())
            ->select('stock_location_id')
            ->selectRaw('SUM(signed_quantity) AS signed_quantity')
            ->groupBy('stock_location_id')
            ->pluck('signed_quantity', 'stock_location_id')
        : StockMovement::query()->forDashboardAggregation()->whereIn('stock_location_id', $this->stockLocations->modelKeys())
            ->select('stock_location_id')->selectSignedQuantity()->groupBy('stock_location_id')
            ->toBase()->pluck('signed_quantity', 'stock_location_id');

    return $this->stockLocations->map(fn (StockLocation $location) => [
        'name' => $location->name,
        'type' => $location->type,
        'quantity' => (float) $quantities->get($location->id, 0),
    ]);
});

$monthlyRevenueExpenseChart = computed(function (): Collection {
    $user = auth()->user();
    $branchId = $this->activeBranchId;
    $lastMonth = now()->startOfMonth();
    $firstMonth = $lastMonth->copy()->subMonths(5);
    $start = $firstMonth->toDateString();
    $end = $lastMonth->copy()->addMonth()->toDateString();
    $monthlyAmounts = static function ($query, string $dateColumn, string $amountColumn) use ($branchId, $start, $end): Collection {
        return $query->when($branchId, fn ($query, $id) => $query->where('branch_id', $id))
            ->where($dateColumn, '>=', $start)->where($dateColumn, '<', $end)
            ->selectRaw("SUBSTR({$dateColumn}, 1, 7) AS calendar_month, SUM({$amountColumn}) AS amount")
            ->groupBy('calendar_month')->toBase()->pluck('amount', 'calendar_month');
    };
    $sales = $user->can('dashboard.sales_summary')
        ? $monthlyAmounts(AuthorizationScope::sales(Sale::query(), $user)->where('status', 'completed'), 'sale_date', 'total_amount')
        : collect();
    $purchases = $user->can('dashboard.purchase_summary')
        ? $monthlyAmounts(Purchase::query()->where('status', '!=', 'cancelled'), 'purchase_date', 'total_amount')
        : collect();
    $expenses = $user->can('dashboard.expenses')
        ? $monthlyAmounts(Expense::query(), 'expense_date', 'amount')
        : collect();

    return collect(range(0, 5))->map(function (int $offset) use ($firstMonth, $sales, $purchases, $expenses) {
        $month = $firstMonth->copy()->addMonths($offset);
        $key = $month->format('Y-m');

        return [
            'month' => $month->locale(app()->getLocale())->translatedFormat('M'),
            'sales' => (float) $sales->get($key, 0),
            'purchases' => (float) $purchases->get($key, 0),
            'expenses' => (float) $expenses->get($key, 0),
        ];
    });
});

$salesBreakdown = computed(function (): array {
    $user = auth()->user();
    $showSales = $user->can('dashboard.sales_summary');
    $showProfit = $user->can('dashboard.profit');
    if (! $showSales && ! $showProfit) {
        return [];
    }

    $today = today()->toDateString();
    $sales = $this->dashboardSales()
        ->where('sales.sale_date', '>=', today()->startOfMonth()->toDateString())
        ->where('sales.sale_date', '<', today()->addDay()->toDateString())
        ->select('sales.id', 'sales.sale_date');
    $items = SaleItem::query()
        ->joinSub($sales, 'dashboard_sales', 'dashboard_sales.id', '=', 'sale_items.sale_id');
    if ($showSales) {
        $items->selectRaw("SUM(CASE WHEN sale_items.sale_type = 'retail' THEN sale_items.line_total ELSE 0 END) AS retail_month,
            SUM(CASE WHEN sale_items.sale_type = 'wholesale' THEN sale_items.line_total ELSE 0 END) AS wholesale_month,
            SUM(CASE WHEN sale_items.sale_type = 'retail' AND dashboard_sales.sale_date >= ? THEN sale_items.line_total ELSE 0 END) AS retail_today,
            SUM(CASE WHEN sale_items.sale_type = 'wholesale' AND dashboard_sales.sale_date >= ? THEN sale_items.line_total ELSE 0 END) AS wholesale_today",
            [$today, $today]);
    }
    if ($showProfit) {
        $profit = 'sale_items.line_total - sale_items.quantity * COALESCE(sale_items.unit_cost, 0)';
        $items->selectRaw("SUM({$profit}) AS profit_month,
            SUM(CASE WHEN dashboard_sales.sale_date >= ? THEN {$profit} ELSE 0 END) AS profit_today", [$today]);
    }

    return (array) $items->toBase()->first();
});

$productSalesMetrics = computed(function (): array {
    if (! auth()->user()->can('dashboard.sales_summary')) {
        return ['products' => collect(), 'categories' => collect()];
    }

    $profitExpression = auth()->user()->can('dashboard.profit')
        ? 'sum(sale_items.line_total - (sale_items.quantity * sale_items.unit_cost))'
        : '0';

    $items = SaleItem::query();
    $connection = $items->getConnection();
    if ($connection->getDriverName() === 'mysql') {
        // The company-only index requires a clustered row read for every ranked sale item.
        $items->forceIndex('sale_items_dashboard_cover');
    }
    $totals = $items->joinSub($this->dashboardSales()->select('sales.id'), 'dashboard_sales', 'dashboard_sales.id', '=', 'sale_items.sale_id')
        ->selectRaw("sale_items.product_id, SUM(sale_items.quantity) AS quantity_sold,
            SUM(sale_items.line_total) AS total_sales, {$profitExpression} AS profit_amount,
            SUM(CASE WHEN sale_items.sale_type = 'wholesale' THEN sale_items.quantity END) AS wholesale_quantity,
            SUM(CASE WHEN sale_items.sale_type = 'wholesale' THEN sale_items.line_total END) AS wholesale_total")
        ->groupBy('sale_items.product_id')->toBase();
    $ranked = $connection->query()->from('product_totals')->select('product_totals.*')
        ->selectRaw('ROW_NUMBER() OVER (ORDER BY quantity_sold DESC, product_id) AS sales_rank,
            ROW_NUMBER() OVER (ORDER BY CASE WHEN wholesale_total IS NULL THEN 1 ELSE 0 END,
                wholesale_total DESC, product_id) AS wholesale_rank');
    $products = $connection->query()->fromSub($ranked, 'ranked_products')
        ->select('ranked_products.*')->selectRaw('NULL AS category_name')
        ->where('sales_rank', '<=', 6)
        ->orWhere(fn ($query) => $query->whereNotNull('wholesale_total')->where('wholesale_rank', '<=', 5));
    $categoryTotals = $connection->query()->from('product_totals')
        ->join('products', 'products.id', '=', 'product_totals.product_id')
        ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
        ->selectRaw("COALESCE(categories.name, 'Uncategorized') AS category_name, SUM(product_totals.total_sales) AS total_sales")
        ->groupBy('category_name')->orderByDesc('total_sales')->limit(8);
    $categories = $connection->query()->fromSub($categoryTotals, 'category_totals')
        ->selectRaw('NULL, NULL, total_sales, NULL, NULL, NULL, NULL, NULL, category_name');

    $rows = collect($connection->select(
        'WITH product_totals AS ('.$totals->toSql().') '.$products->toSql().' UNION ALL '.$categories->toSql(),
        [...$totals->getBindings(), ...$products->getBindings(), ...$categories->getBindings()],
    ));
    $productRows = $rows->whereNotNull('product_id')->map(fn ($row): array => (array) $row);

    return [
        'products' => SaleItem::hydrate($productRows->all())->load('product'),
        'categories' => $rows->whereNotNull('category_name')->sortByDesc('total_sales')->values(),
    ];
});

$salesByCategoryChart = computed(fn (): Collection => $this->productSalesMetrics['categories']);

$topSellingProducts = computed(fn (): Collection => $this->productSalesMetrics['products']
    ->where('sales_rank', '<=', 6)->sortBy('sales_rank')->values());

$topWholesaleProducts = computed(fn (): Collection => $this->productSalesMetrics['products']
    ->whereNotNull('wholesale_total')->where('wholesale_rank', '<=', 5)->sortBy('wholesale_rank')->values());

$topWholesaleCustomers = computed(function (): Collection {
    if (! auth()->user()->can('dashboard.sales_summary')) {
        return collect();
    }

    $totals = $this->dashboardSales()
        ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
        ->where('sale_items.sale_type', 'wholesale')->whereNotNull('sales.customer_id')
        ->select('sales.customer_id')->selectRaw('SUM(sale_items.line_total) AS wholesale_total')
        ->groupBy('sales.customer_id');

    return Customer::query()->select('customers.id', 'customers.name', 'wholesale_totals.wholesale_total')
        ->joinSub($totals, 'wholesale_totals', 'wholesale_totals.customer_id', '=', 'customers.id')
        ->orderByDesc('wholesale_total')->limit(5)->get();
});

$recentTransactions = computed(function (): Collection {
    [$from, $to] = $this->dateRange();
    $branchId = $this->activeBranchId;
    $warehouseEnabled = InventorySettings::warehouseEnabled();

    $sales = auth()->user()->can('dashboard.sales_summary') ? AuthorizationScope::sales(Sale::query(), auth()->user())->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Sale $sale) => [
        'type' => 'Sale',
        'reference' => $sale->sale_number,
        'amount' => (float) $sale->total_amount,
        'status' => $sale->status,
        'date' => $sale->created_at,
        'route' => route('sales.show', $sale),
    ])->toBase() : collect();

    $purchases = auth()->user()->can('dashboard.purchase_summary') ? Purchase::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Purchase $purchase) => [
        'type' => 'Purchase',
        'reference' => $purchase->reference_number,
        'amount' => (float) $purchase->total_amount,
        'status' => $purchase->status,
        'date' => $purchase->created_at,
        'route' => route('purchases.show', $purchase),
    ])->toBase() : collect();

    $transfers = auth()->user()->can('dashboard.stock_summary') ? StockTransfer::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (StockTransfer $transfer) => [
        'type' => 'Transfer',
        'reference' => $transfer->transfer_number,
        'amount' => null,
        'status' => $transfer->status,
        'date' => $transfer->created_at,
        'route' => route('stock-transfers.show', $transfer),
    ])->toBase() : collect();

    $expenses = auth()->user()->can('dashboard.expenses') ? Expense::query()->with('category')->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (Expense $expense) => [
        'type' => 'Expense',
        'reference' => $expense->reference_number ?: $expense->category?->name,
        'amount' => (float) $expense->amount,
        'status' => $expense->payment_method,
        'date' => $expense->created_at,
        'route' => route('expenses.index'),
    ])->toBase() : collect();

    $customerPayments = auth()->user()->can('dashboard.receivables') ? CustomerPayment::query()->with('customer')->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest()->limit(5)->get()->map(fn (CustomerPayment $payment) => [
        'type' => 'Customer Payment',
        'reference' => $payment->reference_number ?: $payment->customer?->name,
        'amount' => (float) $payment->amount,
        'status' => $payment->payment_method,
        'date' => $payment->created_at,
        'route' => route('customer-balances.show', $payment->customer_id),
    ])->toBase() : collect();

    return $sales
        ->merge($warehouseEnabled && auth()->user()->can('dashboard.purchase_summary') ? $purchases : collect())
        ->merge($warehouseEnabled && auth()->user()->can('dashboard.stock_summary') ? $transfers : collect())
        ->merge(auth()->user()->can('dashboard.expenses') ? $expenses : collect())
        ->merge(auth()->user()->can('dashboard.receivables') ? $customerPayments : collect())
        ->filter(fn (array $row) => $row['date']->between($from, $to) || $this->dateFilter === 'today')
        ->sortByDesc('date')
        ->take(12)
        ->values();
});

?>

<div data-tour="dashboard-overview" class="max-w-full min-w-0 overflow-x-hidden space-y-6">
@can('customer_requests.view')
    @php
        $pendingCustomerRequests = \App\Models\CustomerPurchaseRequest::query()->where('company_id', auth()->user()->company_id)->whereIn('status', ['pending','under_review'])->count();
    @endphp
    <a href="{{ route('customer-requests.index') }}" wire:navigate class="mb-5 flex min-h-16 items-center justify-between rounded-xl border border-orange-200 bg-orange-50 p-4 font-black text-build-orange dark:border-orange-700 dark:bg-orange-500/10"><span>{{ __('portal.pending_requests') }}</span><span>{{ $pendingCustomerRequests }}</span></a>
@endcan

    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
        $currency = 'TZS';
        $formatMoney = fn ($value) => $currency.' '.\App\Support\NumberFormatter::money($value);
        $formatQuantity = fn ($value) => \App\Support\NumberFormatter::quantity($value);
        $today = today()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = today()->toDateString();
        $user = auth()->user();
        $canSeeCard = function (array $card) use ($user): bool {
            $label = strtolower($card['label']);
            $ability = match (true) {
                str_contains($label, 'profit') => 'dashboard.profit',
                str_contains($label, 'stock value') => 'dashboard.stock_value',
                str_contains($label, 'expense') => 'dashboard.expenses',
                str_contains($label, 'purchase'), str_contains($label, 'supplier'), str_contains($label, 'email') => 'dashboard.purchase_summary',
                str_contains($label, 'stock'), str_contains($label, 'product') => 'dashboard.stock_summary',
                str_contains($label, 'credit'), str_contains($label, 'payment'), str_contains($label, 'deposit') => 'dashboard.receivables',
                str_contains($label, 'sale'), str_contains($label, 'wholesale') => 'dashboard.sales_summary',
                str_contains($label, 'customer') => 'dashboard.receivables',
                str_contains($label, 'announcement'), str_contains($label, 'message') => 'settings.manage',
                default => 'dashboard.view',
            };

            return $user?->can($ability) ?? false;
        };
        $maxTrend = max(1, $this->salesTrendChart->max('sales') ?: 1);
        $maxCategory = max(1, $this->salesByCategoryChart->max('total_sales') ?: 1);
        $maxStock = $user->can('dashboard.stock_summary') ? max(1, $this->stockDistributionChart->max('quantity') ?: 1) : 1;
        $maxMonthly = $user->canAny(['dashboard.purchase_summary', 'dashboard.expenses']) ? max(1, $this->monthlyRevenueExpenseChart->map(fn ($row) => max($row['sales'], $row['purchases'], $row['expenses']))->max() ?: 1) : 1;
        $warehouseEnabled = \App\Support\InventorySettings::warehouseEnabled();
        $settings = Setting::query()->first();
        $emailSettingsConfigured = filled($settings?->mail_host)
            && filled($settings?->mail_username)
            && filled($settings?->mail_password)
            && filled($settings?->mail_port);
        $branchId = $this->activeBranchId;
        $purchaseValueForDate = fn (string $date): float => ! $user->can('dashboard.purchase_summary') ? 0.0 : (float) Purchase::query()
            ->where('status', '!=', 'cancelled')
            ->whereDate('purchase_date', $date)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('total_amount');
        $customerDebt = $user->can('dashboard.receivables') ? (float) Customer::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('balance_amount') : 0.0;
        $supplierDebt = $user->can('dashboard.purchase_summary') ? (float) Purchase::query()
            ->where('status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('balance_amount') : 0.0;
        $customerDepositBalance = $user->can('dashboard.receivables') ? (float) CustomerDeposit::query()
            ->whereIn('status', ['approved', 'partial'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('balance_amount') : 0.0;
        $cards = collect([
            ['label' => "Today's Sales", 'display_label' => __('dashboard.sales_today'), 'value' => $formatMoney($this->todaySales), 'tone' => 'text-emerald-600', 'display_hint' => __('dashboard.sales_today_help'), 'url' => route('sales.index', ['status' => 'completed', 'date_from' => $today, 'date_to' => $today, 'view' => 'items']), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Retail Sales Today', 'display_label' => __('dashboard.retail_sales_today'), 'value' => $formatMoney((float) ($this->salesBreakdown['retail_today'] ?? 0)), 'tone' => 'text-blue-600', 'display_hint' => __('dashboard.retail_sales_today_help'), 'url' => route('sales.index', ['status' => 'completed', 'sale_type' => 'retail', 'date_from' => $today, 'date_to' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Wholesale Sales Today', 'display_label' => __('dashboard.wholesale_sales_today'), 'value' => $formatMoney((float) ($this->salesBreakdown['wholesale_today'] ?? 0)), 'tone' => 'text-emerald-600', 'display_hint' => __('dashboard.wholesale_sales_today_help'), 'url' => route('sales.index', ['status' => 'completed', 'sale_type' => 'wholesale', 'date_from' => $today, 'date_to' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Monthly Sales', 'display_label' => __('dashboard.monthly_sales'), 'value' => $formatMoney($this->monthlySales), 'tone' => 'text-navy-900 dark:text-white', 'hint' => now()->locale(app()->getLocale())->translatedFormat('M Y'), 'url' => route('sales.index', ['status' => 'completed', 'date_from' => $monthStart, 'date_to' => $monthEnd]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Retail Sales This Month', 'display_label' => __('dashboard.retail_sales_this_month'), 'value' => $formatMoney((float) ($this->salesBreakdown['retail_month'] ?? 0)), 'tone' => 'text-blue-600', 'hint' => now()->locale(app()->getLocale())->translatedFormat('M Y'), 'url' => route('sales.index', ['status' => 'completed', 'sale_type' => 'retail', 'date_from' => $monthStart, 'date_to' => $monthEnd]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Wholesale Sales This Month', 'display_label' => __('dashboard.wholesale_sales_this_month'), 'value' => $formatMoney((float) ($this->salesBreakdown['wholesale_month'] ?? 0)), 'tone' => 'text-emerald-600', 'hint' => now()->locale(app()->getLocale())->translatedFormat('M Y'), 'url' => route('sales.index', ['status' => 'completed', 'sale_type' => 'wholesale', 'date_from' => $monthStart, 'date_to' => $monthEnd]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => "Today's Profit", 'display_label' => __('dashboard.profit_today'), 'value' => $formatMoney((float) ($this->salesBreakdown['profit_today'] ?? 0)), 'tone' => 'text-emerald-600', 'display_hint' => __('dashboard.profit_today_help'), 'url' => route('reports.profit-loss', ['date_from' => $today, 'date_to' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant']],
            ['label' => 'Monthly Profit', 'display_label' => __('dashboard.monthly_profit'), 'value' => $formatMoney((float) ($this->salesBreakdown['profit_month'] ?? 0)), 'tone' => 'text-emerald-600', 'display_hint' => __('dashboard.monthly_profit_help'), 'url' => route('reports.profit-loss', ['date_from' => $monthStart, 'date_to' => $monthEnd]), 'roles' => ['Admin', 'Manager', 'Accountant']],
            ...($warehouseEnabled && $user->can('dashboard.purchase_summary') ? [['label' => 'Pending Purchases', 'value' => number_format(Purchase::query()->whereIn('status', ['draft', 'ordered', 'partial'])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->count()), 'tone' => 'text-amber-600', 'hint' => 'Purchases waiting for receiving', 'url' => route('purchases.index', ['status' => 'ordered']), 'roles' => ['Admin', 'Manager', 'Accountant', 'Store Keeper']]] : []),
            ...($user->can('dashboard.stock_summary') ? [['label' => 'Stock Received Today', 'display_label' => __('dashboard.stock_received_today'), 'value' => $formatQuantity(app(\App\Services\FinancialReportService::class)->stockReceivedToday($branchId)), 'tone' => 'text-emerald-600', 'display_hint' => __('dashboard.stock_received_today_help'), 'url' => route('stock-movements.index', ['receipts_today' => 1, 'branch_id' => $branchId]), 'roles' => ['Admin', 'Manager', 'Accountant', 'Store Keeper']]] : []),
            ...(\App\Support\InventorySettings::directStockInAllowed() && $user->can('stock.direct_stock_in') ? [['label' => 'Direct Stock In Today', 'value' => number_format(StockMovement::where('company_id', $user->company_id)->where('movement_type', 'direct_stock_in')->where('movement_date', '>=', $today)->where('movement_date', '<', today()->addDay()->toDateString())->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->count()), 'tone' => 'text-cyan-600', 'hint' => 'Direct stock entries today', 'url' => route('direct-stock-in.index'), 'roles' => ['Admin', 'Manager', 'Store Keeper']]] : []),
            ...(! $warehouseEnabled ? [['label' => 'Products In Stock', 'value' => number_format(Product::query()->where('status', 'active')->whereHas('stockMovements', fn ($query) => $query->when($branchId, fn ($movementQuery) => $movementQuery->where('branch_id', $branchId)))->count()), 'tone' => 'text-emerald-600', 'hint' => 'Products with stock movement history', 'url' => route('dispensing-stock.index'), 'roles' => ['Admin', 'Manager', 'Store Keeper', 'Cashier']]] : []),
            ['label' => 'Low Stock Products', 'value' => number_format($this->lowStockAlerts), 'tone' => 'text-amber-600', 'hint' => 'At or below reorder level', 'url' => route('inventory-summary.index', ['statusFilter' => 'low_stock']), 'roles' => ['Admin', 'Manager', 'Store Keeper']],
            ['label' => 'Credit Customers', 'value' => $formatMoney($customerDebt), 'tone' => 'text-red-600', 'hint' => 'Outstanding customer balances', 'url' => route('customer-balances.index', ['balance' => 'outstanding']), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Pending Payments', 'value' => number_format(Sale::query()->where('status', 'completed')->whereIn('payment_status', ['unpaid', 'partial'])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->count()), 'tone' => 'text-amber-600', 'hint' => 'Unpaid and partial invoices', 'url' => route('sales.index', ['status' => 'completed', 'payment_status' => 'pending']), 'roles' => ['Admin', 'Manager', 'Accountant', 'Cashier']],
            ['label' => 'Customers Registered Today', 'value' => number_format(Customer::query()->whereDate('created_at', $today)->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->count()), 'tone' => 'text-cyan-600', 'hint' => 'New customers today', 'url' => route('customers.index', ['created_from' => $today, 'created_to' => $today]), 'roles' => ['Admin', 'Manager', 'Cashier']],
            ['label' => 'Products Added Today', 'value' => number_format(Product::query()->whereDate('created_at', $today)->when($branchId, fn ($query) => $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))->count()), 'tone' => 'text-cyan-600', 'hint' => 'Products created today', 'url' => route('products.index', ['created_from' => $today, 'created_to' => $today]), 'roles' => ['Admin', 'Manager', 'Store Keeper']],
            ...($warehouseEnabled ? [['label' => 'Stock Transfers Today', 'value' => number_format(StockTransfer::query()->whereDate('transfer_date', $today)->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->count()), 'tone' => 'text-cyan-600', 'hint' => 'Transfers created today', 'url' => route('stock-transfers.index', ['dateFrom' => $today, 'dateTo' => $today]), 'roles' => ['Admin', 'Manager', 'Store Keeper']]] : []),
            ['label' => 'Expenses Today', 'value' => $formatMoney(Expense::query()->whereDate('expense_date', $today)->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->sum('amount')), 'tone' => 'text-red-600', 'hint' => 'Operating costs today', 'url' => route('expenses.index', ['date_from' => $today, 'date_to' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant']],
            ...($warehouseEnabled ? [['label' => 'Supplier Balances', 'value' => $formatMoney($supplierDebt), 'tone' => 'text-red-600', 'hint' => 'Outstanding supplier balances', 'url' => route('supplier-balances.index'), 'roles' => ['Admin', 'Manager', 'Accountant']]] : []),
            ['label' => 'Customer Deposits', 'value' => $formatMoney($customerDepositBalance), 'tone' => 'text-emerald-600', 'hint' => 'Available customer deposit balance', 'url' => route('admin.customer-deposits.index'), 'roles' => ['Admin', 'Manager', 'Accountant']],
            ...($emailSettingsConfigured ? [
                ['label' => 'Purchase Orders Sent Today', 'value' => number_format(PurchaseEmailLog::where('status', 'sent')->whereDate('sent_at', today())->count()), 'tone' => 'text-emerald-600', 'hint' => 'Supplier PO emails delivered', 'url' => route('purchase-email-logs.index', ['statusFilter' => 'sent', 'dateFrom' => $today, 'dateTo' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant']],
                ['label' => 'Failed Purchase Emails', 'value' => number_format(PurchaseEmailLog::where('status', 'failed')->count()), 'tone' => 'text-red-600', 'hint' => 'Needs SMTP or recipient review', 'url' => route('purchase-email-logs.index', ['statusFilter' => 'failed']), 'roles' => ['Admin', 'Manager', 'Accountant']],
                ['label' => 'Pending Purchase Emails', 'value' => number_format(PurchaseEmailLog::where('status', 'pending')->count()), 'tone' => 'text-amber-600', 'hint' => 'Queued and waiting for workers', 'url' => route('purchase-email-logs.index', ['statusFilter' => 'pending']), 'roles' => ['Admin', 'Manager', 'Accountant']],
                ['label' => 'Suppliers Contacted Today', 'value' => number_format(PurchaseEmailLog::whereDate('created_at', $today)->distinct('recipient_email')->count('recipient_email')), 'tone' => 'text-cyan-600', 'hint' => 'Unique supplier emails today', 'url' => route('purchase-email-logs.index', ['dateFrom' => $today, 'dateTo' => $today]), 'roles' => ['Admin', 'Manager', 'Accountant']],
                ['label' => 'Email Queue', 'value' => number_format(PurchaseEmailLog::where('status', 'pending')->count()), 'tone' => 'text-amber-600', 'hint' => 'Queued purchase email jobs', 'url' => route('purchase-email-logs.index', ['statusFilter' => 'pending']), 'roles' => ['Admin', 'Manager', 'Accountant']],
            ] : []),
            ['label' => 'Announcements Sent', 'value' => number_format(CustomerNotification::where('type', 'announcement')->count()), 'tone' => 'text-cyan-600', 'hint' => 'Portal announcement deliveries', 'url' => route('admin.announcements.index'), 'roles' => ['Admin', 'Manager']],
            ['label' => 'Unread Customer Messages', 'value' => number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNull('read_at')->count()), 'tone' => 'text-amber-600', 'hint' => 'Waiting for customer read', 'url' => route('admin.customer-notifications.index'), 'roles' => ['Admin', 'Manager']],
            ['label' => 'Customers Reached', 'value' => number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->distinct('customer_id')->count('customer_id')), 'tone' => 'text-emerald-600', 'hint' => 'Unique customers notified', 'url' => route('admin.sent-messages.index'), 'roles' => ['Admin', 'Manager']],
        ])->filter(fn (array $card) => $canSeeCard($card))->values();
        $recentAnnouncements = Announcement::query()->latest()->limit(5)->get();
    @endphp

    <x-page-header
        title="{{ __('application.hardex_pos_da25962f') }}"
        description="{{ __('application.business_dashboard_for_sales_inventory_cash_flow_and_operational_alerts_9a1aa2df') }}"
        :breadcrumbs="['Dashboard' => null]"
    >
        <div class="flex flex-wrap gap-2">
            @can('sales.create')
                <a href="{{ route('pos.index') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('dashboard.open_pos') }}</a>
            @endcan
            @can('reports.profit')
                <a href="{{ route('reports.profit-loss') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('dashboard.profit_report') }}</a>
            @endcan
        </div>
    </x-page-header>

    <x-card>
        <div class="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <label class="block text-sm font-bold">
                {{ $t(__('application.date_filter_900cb837')) }}
                <select wire:model.live="dateFilter" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="today">{{ $t(__('application.today_24345a14')) }}</option>
                    <option value="this_week">{{ $t(__('application.this_week_4e68dca8')) }}</option>
                    <option value="this_month">{{ $t(__('application.this_month_0f6cc3a8')) }}</option>
                    <option value="this_year">{{ $t(__('application.this_year_77528c94')) }}</option>
                    <option value="custom">{{ $t(__('application.custom_range_f130609d')) }}</option>
                </select>
            </label>
            <label class="block text-sm font-bold">
                {{ $t(__('application.branch_1627510b')) }}
                <select wire:model.live="branchFilter" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(! $this->canViewAllBranches)>
                    @if ($this->canViewAllBranches)
                        <option value="">{{ $t(__('application.all_branches_25c23746')) }}</option>
                    @endif
                    @foreach ($this->branchOptions as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold">
                {{ $t(__('application.from_3f66052a')) }}
                <input wire:model.live="customFrom" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-navy-950" @disabled($dateFilter !== 'custom')>
            </label>
            <label class="block text-sm font-bold">
                {{ $t(__('application.to_ae79ea1e')) }}
                <input wire:model.live="customTo" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-navy-950" @disabled($dateFilter !== 'custom')>
            </label>
            <div class="flex items-end">
                <div wire:loading class="rounded-lg bg-orange-50 px-3 py-2 text-sm font-bold text-build-orange dark:bg-orange-500/10">{{ $t(__('application.refreshing_5b7bdb61')) }}</div>
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

    @if (! $emailSettingsConfigured && $user->can('dashboard.purchase_summary'))
        <!-- <div wire:loading.remove.delay class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-black text-amber-900 dark:text-amber-100">{{ $t(__('application.email_settings_have_not_been_configured_160ad2b3')) }}</p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">{{ $t(__('application.email_sending_is_currently_unavailable_because_email_settings_have_not_been_edd099eb')) }}</p>
                </div>
                <a href="{{ route('email-settings.index') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center rounded-xl bg-amber-500 px-4 text-sm font-black text-white shadow-sm hover:bg-amber-600">
                    {{ $t(__('application.configure_email_settings_b5f4b5f2')) }}
                </a>
            </div>
        </div> -->
    @endif

    <div wire:loading.remove.delay data-tour="dashboard-stats" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ $card['url'] }}" wire:navigate class="group block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:scale-[1.01] hover:border-cyan-400 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-cyan-400 dark:border-slate-800 dark:bg-navy-900 dark:hover:border-cyan-400">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $card['display_label'] ?? $t($card['label']) }}</p>
                        <p class="mt-3 text-2xl font-black {{ $card['tone'] }}">{{ $card['value'] }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ $card['display_hint'] ?? $t($card['hint']) }}</p>
                        <p class="mt-4 text-xs font-black text-cyan-600 transition group-hover:text-cyan-500 dark:text-cyan-300">{{ __('dashboard.view_details') }} -></p>
                    </div>
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-cyan-400 to-blue-600 text-sm font-black text-white shadow-lg shadow-cyan-500/20">{{ collect(explode(' ', $card['label']))->map(fn ($word) => $word[0])->take(2)->join('') }}</span>
                </div>
            </a>
        @endforeach
    </div>

    @can('dashboard.stock_value')
        <x-card :title="__('dashboard.stock_value_by_location')" :description="__('dashboard.stock_value_by_location_help')">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-700">
                <span class="font-bold">{{ __('dashboard.total_stock_value') }}</span>
                <a href="{{ route('reports.stock-valuation', ['branch_id' => $branchId]) }}" wire:navigate class="text-2xl font-black text-cyan-600 dark:text-cyan-300">{{ $formatMoney($this->stockLocationValues->sum('value')) }}</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @forelse ($this->stockLocationValues as $locationValue)
                    <a wire:key="valuation-{{ $locationValue['stock_location_id'] }}" href="{{ route('reports.stock-valuation', ['stock_location_id' => $locationValue['stock_location_id'], 'branch_id' => $branchId]) }}" wire:navigate class="rounded-xl border border-slate-200 p-4 transition hover:border-cyan-500 dark:border-slate-700">
                        <p class="font-bold">{{ $locationValue['location'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $locationValue['branch'] ?? __('dashboard.company_wide_location') }}</p>
                        <p class="mt-3 text-xl font-black">{{ $formatMoney($locationValue['value']) }}</p>
                        <p class="mt-3 text-xs font-bold text-cyan-600 dark:text-cyan-300">{{ __('dashboard.view_details') }} &rarr;</p>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">{{ __('dashboard.no_stock_locations') }}</p>
                @endforelse
            </div>
        </x-card>
    @endcan

    <div data-tour="dashboard-charts" class="grid min-w-0 gap-6 xl:grid-cols-2">
        @can('settings.manage')
        <x-card title="{{ __('application.recent_announcements_52de58b3') }}" description="{{ __('application.latest_customer_notices_and_read_progress_fb285435') }}">
            <div class="space-y-3">
                @forelse ($recentAnnouncements as $announcement)
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">{{ $announcement->title }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $t(\App\Support\UiText::status($announcement->status)) }} · {{ $t(\App\Support\UiText::label($announcement->priority)) }}</p>
                            </div>
                            <a href="{{ route('admin.announcements.index') }}" wire:navigate class="text-xs font-bold text-build-orange">{{ $t(__('application.open_cf9b7706')) }}</a>
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">{{ $t(__('application.no_announcements_yet_02975a37')) }}</p>
                @endforelse
            </div>
        </x-card>
        @endcan

        @can('dashboard.sales_summary')
        <x-card title="{{ __('application.interactive_sales_trend_6e4e80b9') }}" :description="$user->can('dashboard.profit') ? __('application.chart_js_view_of_sales_and_profit_for_the_last_7_days_a5423e96') : __('application.chart_js_view_of_your_authorized_sales_for_the_last_7_days_8c3a6a03')">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'line',
                            data: {
                                labels: @js($this->salesTrendChart->pluck('date')->values()),
                                datasets: [
                                    { label: @js($t('Sales')), data: @js($this->salesTrendChart->pluck('sales')->values()), borderColor: buildMartThemeColor(), backgroundColor: buildMartThemeColorAlpha(0.14), tension: 0.35, fill: true },
                                    @if ($user->can('dashboard.profit'))
                                        { label: @js($t('Profit')), data: @js($this->salesTrendChart->pluck('profit')->values()), borderColor: '#059669', backgroundColor: 'rgba(5, 150, 105, 0.12)', tension: 0.35, fill: true }
                                    @endif
                                ]
                            }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="{{ $t(__('application.sales_trend_chart_24ff750d')) }}"></canvas>
            </div>
        </x-card>
        @endcan

        @if ($user->canAny(['dashboard.purchase_summary', 'dashboard.expenses']))
        <x-card title="{{ __('application.revenue_vs_expenses_185e7487') }}" description="{{ __('application.monthly_revenue_purchases_and_expenses_from_database_records_f9b348c8') }}">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: @js($this->monthlyRevenueExpenseChart->pluck('month')->values()),
                                datasets: [
                                    { label: @js($t('Revenue')), data: @js($this->monthlyRevenueExpenseChart->pluck('sales')->values()), backgroundColor: '#059669' },
                                    { label: @js($t('Purchases')), data: @js($this->monthlyRevenueExpenseChart->pluck('purchases')->values()), backgroundColor: '#0d2e50' },
                                    { label: @js($t('Expenses')), data: @js($this->monthlyRevenueExpenseChart->pluck('expenses')->values()), backgroundColor: '#ef4444' }
                                ]
                            }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="{{ $t(__('application.monthly_revenue_versus_expenses_chart_cb92fec8')) }}"></canvas>
            </div>
        </x-card>
        @endif

        @can('dashboard.sales_summary')
        <x-card title="{{ __('application.category_performance_74acc0cd') }}" description="{{ __('application.sales_amount_grouped_by_product_category_f85b744e') }}">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'doughnut',
                            data: {
                                labels: @js($this->salesByCategoryChart->map(fn ($row) => $row->category_name === 'Uncategorized' ? $t('Uncategorized') : $row->category_name)->values()),
                                datasets: [{ label: @js($t('Sales')), data: @js($this->salesByCategoryChart->pluck('total_sales')->values()), backgroundColor: [buildMartThemeColor(), '#0d2e50', '#059669', '#f59e0b', '#3b82f6', '#ef4444', '#8b5cf6', '#14b8a6'] }]
                            },
                            options: { scales: { x: { display: false }, y: { display: false } } }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="{{ $t(__('application.sales_by_category_chart_3060ee65')) }}"></canvas>
            </div>
        </x-card>
        @endcan

        @can('dashboard.stock_summary')
        <x-card title="{{ __('application.stock_distribution_fbeac3b6') }}" description="{{ __('application.current_stock_quantity_grouped_by_stock_location_f128451d') }}">
            <div
                class="h-72 min-w-0 overflow-hidden"
                x-data="{
                    render() {
                        buildMartChart($refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: @js($this->stockDistributionChart->pluck('name')->values()),
                                datasets: [{ label: @js($t('Quantity')), data: @js($this->stockDistributionChart->pluck('quantity')->values()), backgroundColor: buildMartThemeColor() }]
                            },
                            options: { indexAxis: 'y' }
                        });
                    }
                }"
                x-init="render(); window.addEventListener('buildmart-theme-changed', () => render())"
            >
                <canvas class="max-w-full" x-ref="canvas" aria-label="{{ $t(__('application.stock_distribution_chart_51e84c5e')) }}"></canvas>
            </div>
        </x-card>
        @endcan
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-2">
        @can('dashboard.sales_summary')
        <x-card title="{{ __('application.sales_trend_9644346d') }}" :description="$user->can('dashboard.profit') ? __('application.completed_sales_total_and_profit_for_the_last_7_days_e3814c08') : __('application.your_authorized_completed_sales_for_the_last_7_days_c6361ebb')">
            @if ($this->salesTrendChart->isEmpty())
                <p class="py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_sales_trend_data_available_e479c006')) }}</p>
            @else
                <div class="space-y-4">
                    @foreach ($this->salesTrendChart as $row)
                        <div class="grid min-w-0 grid-cols-[64px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[72px_minmax(0,1fr)]">
                            <p class="text-xs font-bold text-slate-500">{{ $row['date'] }}</p>
                            <div>
                                <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-build-orange" style="width: {{ min(100, ($row['sales'] / $maxTrend) * 100) }}%"></div></div>
                                <p class="mt-1 text-xs text-slate-500">{{ $t(__('application.sales_d0edfb6e')) }} {{ $formatMoney($row['sales']) }} @if ($user->can('dashboard.profit')) / {{ $t(__('application.profit_8544a477')) }} {{ $formatMoney($row['profit']) }} @endif</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
        @endcan

        @if ($user->canAny(['dashboard.purchase_summary', 'dashboard.expenses']))
        <x-card title="{{ __('application.monthly_revenue_vs_expenses_d965b6e2') }}" description="{{ __('application.sales_revenue_purchases_and_operating_expenses_by_month_b30e8790') }}">
            <div class="space-y-4">
                @foreach ($this->monthlyRevenueExpenseChart as $row)
                    <div class="grid min-w-0 grid-cols-[44px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[48px_minmax(0,1fr)]">
                        <p class="text-xs font-bold text-slate-500">{{ $row['month'] }}</p>
                        <div class="space-y-1">
                            <div class="h-2 rounded bg-emerald-500" style="width: {{ min(100, ($row['sales'] / $maxMonthly) * 100) }}%"></div>
                            <div class="h-2 rounded bg-navy-700 dark:bg-slate-300" style="width: {{ min(100, ($row['purchases'] / $maxMonthly) * 100) }}%"></div>
                            <div class="h-2 rounded bg-red-500" style="width: {{ min(100, ($row['expenses'] / $maxMonthly) * 100) }}%"></div>
                            <p class="text-xs text-slate-500">{{ $t(__('application.revenue_35cf82f9')) }} {{ $formatMoney($row['sales']) }} / {{ $t(__('application.purchases_6c4e94f1')) }} {{ $formatMoney($row['purchases']) }} / {{ $t(__('application.expenses_8d437dbf')) }} {{ $formatMoney($row['expenses']) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
        @endif

        @can('dashboard.sales_summary')
        <x-card title="{{ __('application.sales_by_category_7e0d0bd2') }}" description="{{ __('application.category_revenue_from_sold_items_30e4daba') }}">
            @forelse ($this->salesByCategoryChart as $row)
                <div class="mb-4">
                    <div class="mb-1 flex min-w-0 justify-between gap-3 text-sm"><span class="min-w-0 truncate font-bold">{{ $row->category_name === 'Uncategorized' ? $t(__('application.uncategorized_4ef7d73c')) : $row->category_name }}</span><span class="shrink-0">{{ $formatMoney($row->total_sales) }}</span></div>
                    <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-build-orange" style="width: {{ min(100, ((float) $row->total_sales / $maxCategory) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_category_sales_found_1cf8cac6')) }}</p>
            @endforelse
        </x-card>
        @endcan

        @can('dashboard.stock_summary')
        <x-card title="{{ __('application.stock_distribution_fbeac3b6') }}" description="{{ __('application.current_stock_quantities_by_location_4d0d85d8') }}">
            @forelse ($this->stockDistributionChart as $row)
                <div class="mb-4">
                    <div class="mb-1 flex min-w-0 justify-between gap-3 text-sm"><span class="min-w-0 truncate font-bold">{{ $row['name'] }} <span class="text-xs font-semibold text-slate-500">({{ $t(\App\Support\UiText::label($row['type'])) }})</span></span><span class="shrink-0">{{ $formatQuantity($row['quantity']) }}</span></div>
                    <div class="h-3 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-3 rounded-full bg-navy-800 dark:bg-build-orange" style="width: {{ min(100, ($row['quantity'] / $maxStock) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_stock_movement_data_found_7bf9b973')) }}</p>
            @endforelse
        </x-card>
        @endcan
    </div>

    @can('dashboard.stock_summary')
    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-card title="{{ __('application.inventory_summary_d629dc53') }}">
                <div class="space-y-3">
                    @foreach ([
                        'Total Products' => $this->inventorySummary['total_products'],
                        'Active Products' => $this->inventorySummary['active_products'],
                        'Out of Stock Products' => $this->inventorySummary['out_of_stock_products'],
                        'Low Stock Products' => $this->inventorySummary['low_stock_products'],
                        ...($warehouseEnabled ? ['Main Store Stock Items' => $this->inventorySummary['main_store_stock_items']] : []),
                        'Selling Stock Items' => $this->inventorySummary['dispensing_stock_items'],
                    ] as $label => $value)
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5">
                            <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ $t($label) }}</span>
                            <span class="font-black">{{ number_format($value) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card title="{{ __('application.system_snapshot_57f91fc1') }}">
                @foreach ([
                    'Total Users' => User::count(),
                    'Active Branches' => Branch::where('status', 'active')->count(),
                    'Active Categories' => Category::where('status', 'active')->count(),
                ] as $label => $value)
                    <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5">
                        <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ $t($label) }}</span>
                        <span class="font-black">{{ number_format($value) }}</span>
                    </div>
                @endforeach
            </x-card>
        </div>

        <x-card title="{{ __('application.low_stock_alerts_b452abc0') }}" description="{{ __('application.products_where_current_total_stock_is_at_or_below_reorder_level_b295a080') }}">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.product_dd3b86d1')) }}</th>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.sku_d59192b9')) }}</th>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.category_a3c686e7')) }}</th>
                        <th class="px-4 py-3 text-right">{{ $t(__('application.current_4fc0e2bc')) }}</th>
                        <th class="px-4 py-3 text-right">{{ $t(__('application.reorder_33d9978c')) }}</th>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.location_d219c681')) }}</th>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.status_bae7d5be')) }}</th>
                        <th class="px-4 py-3 text-right">{{ $t(__('application.action_97c89a4d')) }}</th>
                    </tr>
                </x-slot:head>
                @forelse ($this->lowStockProducts as $row)
                    @php $product = $row['product']; @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-product-image :product="$product" class="h-10 w-10" />
                                <span class="font-bold">{{ $product->displayNameWithSize() }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $product->sku }}</td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? $t(__('application.uncategorized_4ef7d73c')) }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatQuantity($row['current_stock']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatQuantity($product->reorder_level) }}</td>
                        <td class="px-4 py-3">{{ $row['locations']->isEmpty() ? $t(__('application.no_stock_location_04fc4149')) : $row['locations']->map(fn ($location) => $location['name'].' ('.$formatQuantity($location['quantity']).')')->join(', ') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $row['current_stock'] <= 0 ? $t(__('application.out_of_stock_bc67a8ae')) : $t(__('application.low_stock_023d1181')) }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('products.index') }}" wire:navigate class="text-sm font-bold text-build-orange">{{ $t(__('application.view_69bd4ef9')) }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_low_stock_products_for_the_selected_branch_a13b3215')) }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
    @endcan

    @can('dashboard.sales_summary')
    <div class="grid min-w-0 gap-6 xl:grid-cols-2">
        <x-card title="{{ __('application.top_wholesale_customers_addb0982') }}">
            <x-table :headers="[__('application.customer_0e85749a'), __('application.wholesale_total_a2f7fd78')]">
                @forelse ($this->topWholesaleCustomers as $customer)
                    <tr>
                        <td class="px-4 py-3 font-bold">{{ $customer->displayName() }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatMoney($customer->wholesale_total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_wholesale_customers_yet_4e990360')) }}</td></tr>
                @endforelse
            </x-table>
        </x-card>

        <x-card title="{{ __('application.top_wholesale_products_3b6c8a88') }}">
            <x-table :headers="[__('application.product_dd3b86d1'), __('application.qty_sold_6d4e3556'), __('application.wholesale_total_a2f7fd78')]">
                @forelse ($this->topWholesaleProducts as $row)
                    <tr>
                        <td class="px-4 py-3 font-bold">{{ $row->product?->displayNameWithSize() }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatQuantity($row->wholesale_quantity) }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatMoney($row->wholesale_total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_wholesale_products_yet_bc67e494')) }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
    @endcan

    <div class="grid min-w-0 gap-6 xl:grid-cols-2">
        @can('dashboard.sales_summary')
        <x-card title="{{ __('application.top_selling_products_4fe882f9') }}">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.product_dd3b86d1')) }}</th>
                        <th class="px-4 py-3 text-left">{{ $t(__('application.sku_d59192b9')) }}</th>
                        <th class="px-4 py-3 text-right">{{ $t(__('application.qty_sold_6d4e3556')) }}</th>
                        <th class="px-4 py-3 text-right">{{ $t(__('application.sales_d0edfb6e')) }}</th>
                        @if ($user->can('dashboard.profit'))
                            <th class="px-4 py-3 text-right">{{ $t(__('application.profit_8544a477')) }}</th>
                        @endif
                    </tr>
                </x-slot:head>
                @forelse ($this->topSellingProducts as $row)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-product-image :product="$row->product" class="h-10 w-10" />
                                <span class="font-bold">{{ $row->product?->displayNameWithSize() }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $row->product?->sku }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatQuantity($row->quantity_sold) }}</td>
                        <td class="px-4 py-3 text-right">{{ $formatMoney($row->total_sales) }}</td>
                        @if ($user->can('dashboard.profit'))
                            <td class="px-4 py-3 text-right font-bold text-emerald-600">{{ $formatMoney($row->profit_amount) }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $user->can('dashboard.profit') ? 5 : 4 }}" class="px-4 py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_completed_sales_yet_f0d69c49')) }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
        @endcan

        <x-card title="{{ __('application.recent_transactions_f28b82a4') }}" description="{{ __('application.latest_sales_purchases_transfers_expenses_and_customer_payments_cea61adf') }}">
            <div class="space-y-3">
                @forelse ($this->recentTransactions as $transaction)
                    <a href="{{ $transaction['route'] }}" wire:navigate class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-slate-100 p-3 transition hover:border-build-orange/40 hover:bg-orange-50/40 dark:border-slate-800 dark:hover:bg-orange-500/10">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $t($transaction['type']) }} · {{ $transaction['reference'] }}</p>
                            <p class="text-xs text-slate-500">{{ $transaction['date']->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }} · {{ $t((string) \App\Support\UiText::label($transaction['status'])) }}</p>
                        </div>
                        <p class="shrink-0 text-sm font-black">{{ is_null($transaction['amount']) ? '-' : $formatMoney($transaction['amount']) }}</p>
                    </a>
                @empty
                    <p class="py-10 text-center text-sm text-slate-500">{{ $t(__('application.no_recent_transactions_for_the_selected_filters_d11d5d35')) }}</p>
                @endforelse
            </div>
        </x-card>
    </div>
</div>
