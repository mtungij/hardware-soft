<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\NumberFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportExportService
{
    public function getCompanyHeader(Request $request): array
    {
        $settings = \App\Models\Setting::query()->first();
        $branchId = $request->integer('branch_id') ?: $request->integer('branchFilter');
        $locationId = $request->integer('stock_location_id');
        $branch = $branchId ? Branch::find($branchId) : null;
        $location = $locationId ? StockLocation::find($locationId) : null;

        return [
            'company_name' => $settings?->company_name ?: config('app.name', 'HARDEX ERP'),
            'logo' => $settings?->company_logo ? storage_path('app/public/'.$settings->company_logo) : null,
            'phone' => $settings?->company_phone,
            'whatsapp' => $settings?->whatsapp_number,
            'email' => $settings?->company_email,
            'address' => $settings?->company_address,
            'region' => $settings?->region,
            'district' => $settings?->district,
            'date_range' => trim(($request->string('date_from')->toString() ?: 'Beginning').' to '.($request->string('date_to')->toString() ?: today()->toDateString())),
            'branch_name' => $branch?->name ?? 'All Branches',
            'stock_location' => $location?->name ?? 'All Locations',
            'printed_by' => $request->user()?->name ?? '-',
            'printed_date' => now()->format('Y-m-d H:i'),
        ];
    }

    public function generatePdf(string $reportTitle, array $data, array $filters = []): string
    {
        if (($data['export_key'] ?? null) === 'tables.products') {
            $data['headers'] = ['S/N', ...$data['headers']];
            $data['rows'] = collect($data['rows'])
                ->values()
                ->map(fn (array $row, int $index) => [
                    $index + 1,
                    mb_strtoupper((string) ($row[0] ?? '')),
                    ...array_slice($row, 1),
                ])
                ->all();
            $data['table_theme'] = 'cyan';
        }

        return app(PdfExportService::class)->generatePdf($reportTitle, ['filters' => $filters, ...$data]);
    }

    public function generateExcel(string $reportTitle, array $data, array $filters = [])
    {
        return app(ExcelExportService::class)->generateExcel(str($reportTitle)->slug('_').'.xls', ['title' => $reportTitle, 'filters' => $filters, ...$data]);
    }

    public function formatCurrency(float|int|string|null $value): string
    {
        return 'TZS '.NumberFormatter::money($value);
    }

    public function formatQuantity(float|int|string|null $value): string
    {
        return NumberFormatter::quantity($value);
    }

    public function formatDate($value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '-';
    }

    public function build(string $key, Request $request): array
    {
        $header = $this->getCompanyHeader($request);
        [$title, $headers, $rows, $totals] = $this->rows($key, $request);

        return compact('title', 'header', 'headers', 'rows', 'totals') + [
            'filters' => $request->query(),
            'export_key' => $key,
        ];
    }

    private function rows(string $key, Request $request): array
    {
        $branchId = $request->integer('branch_id') ?: $request->integer('branchFilter') ?: null;
        $from = $request->string('date_from')->toString() ?: $request->string('created_from')->toString();
        $to = $request->string('date_to')->toString() ?: $request->string('created_to')->toString();
        $search = $request->string('search')->toString();

        return match ($key) {
            'reports.sales', 'tables.sales' => $this->sales($request, $branchId, $from, $to, $search),
            'tables.sales-items' => $this->salesItems($request, $branchId, $from, $to, $search),
            'reports.purchases', 'tables.purchases' => $this->purchases($request, $branchId, $from, $to, $search),
            'reports.expenses', 'tables.expenses' => $this->expenses($request, $branchId, $from, $to, $search),
            'reports.customer-payments', 'tables.customer-payments' => $this->customerPayments($request, $branchId, $from, $to),
            'reports.customers', 'tables.customers' => $this->customers($request, $branchId, $search),
            'reports.suppliers', 'tables.suppliers' => $this->suppliers($request, $branchId, $search),
            'reports.stock-valuation', 'tables.inventory-summary' => $this->stockValuation($branchId),
            'reports.profit-loss' => $this->profitLoss($branchId, $from, $to),
            'reports.cashbook', 'tables.cashbook' => $this->cashbook($branchId, $from, $to),
            'tables.products' => $this->products($request, $branchId, $search),
            'tables.store-stock' => $this->storeStock($request, $branchId, $search),
            'tables.categories' => $this->categories($request, $branchId, $search),
            'tables.units' => $this->units($request, $branchId, $search),
            'tables.users' => $this->users($request, $search),
            'tables.branches' => $this->branches($search),
            'tables.stock-movements' => $this->stockMovements($request, $branchId, $from, $to, $search),
            'tables.stock-transfers' => $this->stockTransfers($request, $branchId, $from, $to, $search),
            'tables.email-logs', 'reports.purchase-emails' => $this->purchaseEmailLogs($request, $from, $to, $search),
            default => abort(404),
        };
    }

    private function sales(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Sale::with(['customer', 'soldBy', 'createdBy', 'items.stockLocation'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
            ->when($request->string('sale_type')->toString(), fn ($q, $type) => $q->where('sale_type', $type))
            ->when($request->integer('stock_location_id'), fn ($q, $id) => $q->whereHas('items', fn ($items) => $items->where('stock_location_id', $id)))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('payment_status')->toString(), fn ($q, $status) => $q->where('payment_status', $status === 'pending' ? 'partial' : $status))
            ->when($search, fn ($q) => $q->where(fn ($nested) => $nested->where('sale_number', 'like', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->get();

        return ['Sales Report', ['Sale Number', 'User', 'Stock Location', 'Sale Type', 'Amount', 'Date', 'Customer', 'Paid', 'Balance'], $rows->map(fn ($sale) => [$sale->sale_number, $sale->soldBy?->name ?? $sale->createdBy?->name, $sale->stockLocationLabel(), $sale->saleTypeLabel(), $this->formatCurrency($sale->total_amount), $this->formatDate($sale->sale_date), $sale->customer?->name ?? 'Walk-in', $this->formatCurrency($sale->paid_amount), $this->formatCurrency($sale->balance_amount)])->all(), ['Total Amount' => $this->formatCurrency($rows->sum('total_amount'))]];
    }

    private function salesItems(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $canViewProfit = $request->user()?->can('view sales profit') ?? false;
        $lineCost = fn (SaleItem $item): float => (float) $item->quantity * (float) $item->unit_cost;
        $lineProfit = fn (SaleItem $item): float => (float) ($item->profit_amount ?? ((float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost)));
        $tr = fn (string $key): string => __('messages.sales_items.'.$key);
        $paymentLabel = fn (string $method): string => $tr('method_'.$method);

        $rows = SaleItem::query()
            ->with(['sale.customer', 'sale.soldBy', 'sale.createdBy', 'sale.payments', 'product.unit', 'product.category', 'product.size', 'stockLocation'])
            ->whereHas('sale', function ($query) use ($request, $branchId, $from, $to) {
                $query->where('status', 'completed')
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                    ->when($request->integer('cashier_id'), fn ($q, $id) => $q->where(fn ($cashierQuery) => $cashierQuery->where('sold_by', $id)->orWhere('created_by', $id)))
                    ->when($request->string('payment_method')->toString(), fn ($q, $method) => $q->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('payment_method', $method)))
                    ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to));
            })
            ->when($request->string('sale_type')->toString(), fn ($query, $type) => $query->where('sale_type', $type))
            ->when($request->integer('stock_location_id'), fn ($query, $id) => $query->where('stock_location_id', $id))
            ->when($request->integer('product_id'), fn ($query, $id) => $query->where('product_id', $id))
            ->when($request->integer('category_id'), fn ($query, $id) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('category_id', $id)))
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->whereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%")))
                ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                    ->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('soldBy', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('createdBy', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")))))
            ->latest('id')
            ->get();

        $invoiceRows = $rows
            ->groupBy('sale_id')
            ->map(function ($items) use ($lineCost, $paymentLabel, $tr) {
                $sale = $items->first()->sale;
                $saleTypes = $items->pluck('sale_type')->filter()->unique()->values();
                $stockSources = $items->map(fn ($item) => $item->sold_from_label ?: $item->stockLocation?->name)->filter()->unique()->values();
                $paymentMethods = $sale?->payments?->pluck('payment_method')->filter()->unique()->values() ?? collect();
                $cost = $items->sum(fn ($item) => $lineCost($item));
                $sales = (float) ($sale?->total_amount ?? $items->sum('line_total'));

                return [
                    'sale' => $sale,
                    'items' => $items->values(),
                    'customer' => $sale?->customer?->name ?: $tr('walk_in_customer'),
                    'total_quantity' => $items->sum('quantity'),
                    'cost' => $cost,
                    'sales' => $sales,
                    'profit' => $sales - $cost,
                    'sale_type' => $saleTypes->count() > 1 ? $tr('mixed') : ($saleTypes->first() === 'wholesale' ? $tr('wholesale') : $tr('retail')),
                    'payment_method' => $sale?->payment_status === 'partial' ? $tr('partial') : ($paymentMethods->count() > 1 ? $tr('mixed') : ($paymentMethods->isEmpty() ? '-' : $paymentLabel((string) $paymentMethods->first()))),
                    'stock_source' => $stockSources->count() > 1 ? $tr('mixed_locations') : (string) ($stockSources->first() ?: '-'),
                    'cashier' => $sale?->soldBy?->name ?? $sale?->createdBy?->name,
                ];
            })
            ->sortByDesc(fn ($row) => $row['sale']?->created_at)
            ->values();

        $headers = [$tr('sale_no'), $tr('customer'), $tr('products_sold'), $tr('total_quantity')];

        if ($canViewProfit) {
            $headers[] = $tr('buying_cost');
        }

        $headers[] = $tr('selling_amount');

        if ($canViewProfit) {
            $headers[] = $tr('profit');
        }

        array_push($headers, $tr('sale_type'), $tr('payment_method'), $tr('stock_location'), $tr('cashier'));

        $exportRows = $invoiceRows->map(function (array $invoice) use ($canViewProfit) {
            $row = [
                $invoice['sale']?->sale_number,
                $invoice['customer'],
                $invoice['items']->map(fn ($item) => $item->product?->displayNameWithSize().' x '.$this->formatQuantity($item->quantity).' '.$item->product?->unit?->short_name)->join("\n"),
                $this->formatQuantity($invoice['total_quantity']),
            ];

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($invoice['cost']);
            }

            $row[] = $this->formatCurrency($invoice['sales']);

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($invoice['profit']);
            }

            array_push($row, $invoice['sale_type'], $invoice['payment_method'], $invoice['stock_source'], $invoice['cashier']);

            return $row;
        })->all();

        $totals = [
            $tr('total_sales') => $this->formatCurrency($invoiceRows->sum('sales')),
            $tr('total_customers') => number_format($invoiceRows->pluck('sale.customer_id')->filter()->unique()->count() + ($invoiceRows->contains(fn ($row) => blank($row['sale']?->customer_id)) ? 1 : 0)),
            $tr('total_invoices') => number_format($invoiceRows->count()),
            $tr('cash_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'cash')->sum('amount')),
            $tr('credit_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'credit')->sum('amount')),
        ];

        if ($canViewProfit) {
            $totals = [$tr('total_cost') => $this->formatCurrency($invoiceRows->sum('cost')), $tr('total_profit') => $this->formatCurrency($invoiceRows->sum('profit')), ...$totals];
        }

        return [$tr('todays_sales_summary'), $headers, $exportRows, $totals];
    }

    private function purchases(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Purchase::with('supplier')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to))->when($search, fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")))->latest()->get();
        return ['Purchase Report', ['Date', 'Reference', 'Supplier', 'Status', 'Total', 'Paid', 'Balance'], $rows->map(fn ($purchase) => [$this->formatDate($purchase->purchase_date), $purchase->reference_number, $purchase->supplier?->name, ucfirst($purchase->status), $this->formatCurrency($purchase->total_amount), $this->formatCurrency($purchase->paid_amount), $this->formatCurrency($purchase->balance_amount)])->all(), ['Total Purchases' => $this->formatCurrency($rows->sum('total_amount'))]];
    }

    private function expenses(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Expense::with(['category', 'branch', 'paidBy'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($query) => $query
                ->where('reference_number', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->get();
        return ['Expense Report', ['Date', 'Category', 'Branch', 'Method', 'Paid By', 'Amount'], $rows->map(fn ($expense) => [$this->formatDate($expense->expense_date), $expense->category?->name, $expense->branch?->name, str($expense->payment_method)->replace('_', ' ')->title(), $expense->paidBy?->name, $this->formatCurrency($expense->amount)])->all(), ['Total Expenses' => $this->formatCurrency($rows->sum('amount'))]];
    }

    private function customerPayments(Request $request, ?int $branchId, ?string $from, ?string $to): array
    {
        $customerId = $request->integer('customer_id') ?: null;
        $method = $request->string('payment_method')->toString();
        $receivedBy = $request->integer('received_by') ?: null;

        $rows = CustomerPayment::with(['customer', 'branch', 'receivedBy'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->when($method, fn ($q) => $q->where('payment_method', $method))
            ->when($receivedBy, fn ($q) => $q->where('received_by', $receivedBy))
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->latest('payment_date')
            ->get();

        return [
            'Customer Payment Report',
            ['Date', 'Time', 'Receipt Number', 'Customer', 'Phone', 'Amount', 'Method', 'Reference', 'Branch', 'Received By', 'Notes'],
            $rows->map(fn ($payment) => [
                $this->formatDate($payment->payment_date),
                $payment->created_at?->format('H:i'),
                $payment->receipt_number ?: 'PAY-'.$payment->id,
                $payment->customer?->name,
                $payment->customer?->phone,
                $this->formatCurrency($payment->amount),
                str($payment->payment_method)->replace('_', ' ')->title(),
                $payment->reference_number,
                $payment->branch?->name,
                $payment->receivedBy?->name,
                $payment->notes,
            ])->all(),
            [
                'Cash Payments' => $this->formatCurrency($rows->where('payment_method', 'cash')->sum('amount')),
                'Mobile Money Payments' => $this->formatCurrency($rows->where('payment_method', 'mobile_money')->sum('amount')),
                'Bank Payments' => $this->formatCurrency($rows->where('payment_method', 'bank')->sum('amount')),
                'Total Payments' => $this->formatCurrency($rows->sum('amount')),
            ],
        ];
    }

    private function customers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Customer::with('branch')->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($request->string('typeFilter')->toString(), fn ($q, $type) => $q->where('customer_type', $type))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();
        return ['Customer Balance Report', ['Customer', 'Phone', 'Type', 'Branch', 'Outstanding'], $rows->map(fn ($customer) => [$customer->name, $customer->phone, $customer->customer_type, $customer->branch?->name, $this->formatCurrency($accounting->customerBalance($customer))])->all(), []];
    }

    private function suppliers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Supplier::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();
        return ['Supplier Balance Report', ['Supplier', 'Phone', 'Branch', 'Opening', 'Outstanding'], $rows->map(fn ($supplier) => [$supplier->name, $supplier->phone, $supplier->branch?->name, $this->formatCurrency($supplier->opening_balance), $this->formatCurrency($accounting->supplierBalance($supplier))])->all(), []];
    }

    private function products(Request $request, ?int $branchId, string $search): array
    {
        $rows = Product::with(['category', 'unit', 'branch', 'size'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('categoryFilter'), fn ($q, $id) => $q->where('category_id', $id))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%")))->orderBy('name')->get();
        return ['Products', ['Product', 'Size', 'SKU', 'Category', 'Unit', 'Buying Price', 'Retail Price', 'Wholesale Price', 'Status'], $rows->map(fn ($p) => [$p->displayName(), $p->sizeLabel() ?: '-', $p->sku, $p->category?->name, $p->unit?->short_name, $this->formatCurrency($p->buying_price), $this->formatCurrency($p->selling_price), $this->formatCurrency($p->wholesale_price), ucfirst($p->status)])->all(), []];
    }

    private function storeStock(Request $request, ?int $branchId, string $search): array
    {
        $branchId = $branchId ?: $request->integer('branchFilter') ?: null;
        $locationId = $request->integer('stock_location_id');
        $categoryId = $request->integer('categoryFilter');
        $supplierId = $request->integer('supplier_id');
        $brand = $request->string('brand')->toString();
        $companyId = $request->user()?->company_id;
        $user = $request->user();
        $setting = \App\Support\InventorySettings::current();

        if (($setting->inventory_mode ?? 'multi_location') === 'single_location' && $setting->default_stock_location_id) {
            $locationId = (int) $setting->default_stock_location_id;
        }

        $locationQuery = StockLocation::query()
            ->where('status', 'active')
            ->where('is_active', true)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        if (! $user?->can('view all stock locations')) {
            $locationQuery->whereIn('id', $user?->stockLocations()->wherePivot('can_view', true)->pluck('stock_locations.id') ?? []);
        }

        $allowedLocationIds = $locationQuery->pluck('id')->all();
        $stockExpression = "SUM(CASE WHEN stock_movements.quantity_in <> 0 OR stock_movements.quantity_out <> 0 THEN stock_movements.quantity_in - stock_movements.quantity_out WHEN stock_movements.movement_type IN ('sale_out','transfer_out','adjustment_out','damage_out','purchase_receipt_reversal') THEN -stock_movements.quantity ELSE stock_movements.quantity END)";
        $costNumerator = "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) * stock_movements.unit_cost ELSE 0 END)";
        $costDenominator = "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) ELSE 0 END)";
        $productNameExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "products.name || ' - ' || product_sizes.symbol"
            : "CONCAT(products.name, ' - ', product_sizes.symbol)";

        $stockSubquery = StockMovement::query()
            ->select([
                'company_id',
                'branch_id',
                'product_id',
                'stock_location_id',
                DB::raw("{$stockExpression} as quantity"),
                DB::raw("CASE WHEN {$costDenominator} > 0 THEN {$costNumerator} / {$costDenominator} ELSE 0 END as average_cost"),
            ])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->groupBy('company_id', 'branch_id', 'product_id', 'stock_location_id');

        $rows = DB::table('products')
            ->crossJoin('stock_locations')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoin('product_sizes', 'product_sizes.id', '=', 'products.product_size_id')
            ->leftJoinSub($stockSubquery, 'stock_totals', function ($join) {
                $join->on('stock_totals.product_id', '=', 'products.id')
                    ->on('stock_totals.stock_location_id', '=', 'stock_locations.id')
                    ->on('stock_totals.branch_id', '=', 'stock_locations.branch_id');
            })
            ->where('products.status', 'active')
            ->where('stock_locations.status', 'active')
            ->where('stock_locations.is_active', true)
            ->whereIn('stock_locations.id', $allowedLocationIds ?: [0])
            ->when($companyId, fn ($query) => $query->where('products.company_id', $companyId)->where('stock_locations.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('stock_locations.branch_id', $branchId))
            ->when($locationId, fn ($query) => $query->where('stock_locations.id', $locationId))
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($brand, fn ($query) => $query->where('products.brand', $brand))
            ->when($supplierId, fn ($query) => $query->whereExists(function ($exists) use ($supplierId) {
                $exists->select(DB::raw(1))
                    ->from('purchase_items')
                    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->whereColumn('purchase_items.product_id', 'products.id')
                    ->where('purchases.supplier_id', $supplierId);
            }))
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('products.barcode', 'like', "%{$search}%")
                ->orWhere('product_sizes.name', 'like', "%{$search}%")
                ->orWhere('product_sizes.symbol', 'like', "%{$search}%")))
            ->select([
                DB::raw("CASE WHEN product_sizes.symbol IS NULL OR product_sizes.symbol = '' THEN products.name ELSE {$productNameExpression} END as product_name"),
                'products.sku',
                'categories.name as category_name',
                'units.short_name as unit_name',
                'stock_locations.name as location_name',
                'stock_locations.type as location_type',
                'products.reorder_level',
                DB::raw('COALESCE(stock_totals.quantity, 0) as quantity'),
                DB::raw('COALESCE(stock_totals.average_cost, 0) as average_cost'),
                DB::raw('COALESCE(stock_totals.quantity, 0) * COALESCE(stock_totals.average_cost, 0) as stock_value'),
                DB::raw("CASE WHEN COALESCE(stock_totals.quantity, 0) <= 0 THEN 'out_of_stock' WHEN COALESCE(stock_totals.quantity, 0) <= products.reorder_level THEN 'low_stock' ELSE 'in_stock' END as stock_status"),
            ])
            ->when($request->boolean('low_stock_only'), fn ($query) => $query->whereRaw('COALESCE(stock_totals.quantity, 0) > 0 AND COALESCE(stock_totals.quantity, 0) <= products.reorder_level'))
            ->when($request->boolean('out_of_stock_only'), fn ($query) => $query->whereRaw('COALESCE(stock_totals.quantity, 0) <= 0'))
            ->orderBy('products.name')
            ->orderBy('stock_locations.name')
            ->get();

        return [
            'Stock by Location',
            ['Product', 'SKU', 'Category', 'Unit', 'Stock Location', 'Location Type', 'Quantity', 'Average Cost', 'Stock Value', 'Reorder Level', 'Status'],
            $rows->map(fn ($row) => [
                $row->product_name,
                $row->sku,
                $row->category_name,
                $row->unit_name,
                $row->location_name,
                str($row->location_type)->replace('_', ' ')->title()->toString(),
                $this->formatQuantity($row->quantity),
                $this->formatCurrency($row->average_cost),
                $this->formatCurrency($row->stock_value),
                $this->formatQuantity($row->reorder_level),
                str($row->stock_status)->replace('_', ' ')->title()->toString(),
            ])->all(),
            [
                'Total Quantity' => $this->formatQuantity($rows->sum('quantity')),
                'Total Stock Value' => $this->formatCurrency($rows->sum('stock_value')),
                'Active Locations' => count($allowedLocationIds),
            ],
        ];
    }

    private function categories(Request $request, ?int $branchId, string $search): array
    {
        $rows = Category::with('branch')->withCount('products')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))->latest()->get();
        return ['Categories', ['Name', 'Code', 'Branch', 'Products', 'Status'], $rows->map(fn ($c) => [$c->name, $c->code, $c->branch?->name, $c->products_count, ucfirst($c->status)])->all(), []];
    }

    private function units(Request $request, ?int $branchId, string $search): array
    {
        $rows = Unit::with('branch')->withCount('products')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%"))->latest()->get();
        return ['Units', ['Name', 'Short Name', 'Branch', 'Products', 'Status'], $rows->map(fn ($u) => [$u->name, $u->short_name, $u->branch?->name, $u->products_count, ucfirst($u->status)])->all(), []];
    }

    private function users(Request $request, string $search): array
    {
        $rows = User::with(['branch', 'company', 'roles'])->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))->latest()->get();
        return ['Users', ['Name', 'Email', 'Phone', 'Roles', 'Sales Location', 'Company', 'Branch', 'Status'], $rows->map(fn ($u) => [$u->name, $u->email, $u->phone, $u->roles->pluck('name')->join(', '), $u->sales_location_access, $u->company?->company_name, $u->branch?->name, ucfirst($u->status)])->all(), []];
    }

    private function branches(string $search): array
    {
        $rows = Branch::withCount('users')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('region', 'like', "%{$search}%"))
            ->latest()
            ->get();

        return ['Branches', ['Branch', 'Code', 'Phone', 'Email', 'Region', 'District', 'Users', 'Status'], $rows->map(fn ($branch) => [$branch->name, $branch->code, $branch->phone, $branch->email, $branch->region, $branch->district, $branch->users_count, ucfirst($branch->status)])->all(), []];
    }

    private function stockMovements(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = StockMovement::with(['product.size', 'stockLocation', 'createdBy'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('stock_location_id'), fn ($q, $id) => $q->where('stock_location_id', $id))->when($from, fn ($q) => $q->whereDate('movement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('movement_date', '<=', $to))->when($search, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%"))))->latest()->get();
        return ['Stock Movement Report', ['Date', 'Product', 'Location', 'Type', 'Quantity', 'Cost', 'Created By'], $rows->map(fn ($m) => [$this->formatDate($m->movement_date), $m->product?->displayNameWithSize(), $m->stockLocation?->name, $m->movement_type, $m->quantity, $this->formatCurrency($m->unit_cost), $m->createdBy?->name])->all(), []];
    }

    private function stockTransfers(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = StockTransfer::with(['fromLocation', 'toLocation', 'createdBy'])->withCount('items')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('transfer_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('transfer_date', '<=', $to))->when($search, fn ($q) => $q->where('transfer_number', 'like', "%{$search}%"))->latest()->get();
        return ['Stock Transfer Report', ['Transfer #', 'Date', 'From', 'To', 'Items', 'Status', 'Created By'], $rows->map(fn ($t) => [$t->transfer_number, $this->formatDate($t->transfer_date), $t->fromLocation?->name, $t->toLocation?->name, $t->items_count, ucfirst($t->status), $t->createdBy?->name])->all(), []];
    }

    private function purchaseEmailLogs(Request $request, ?string $from, ?string $to, string $search): array
    {
        $rows = PurchaseEmailLog::with(['purchase.supplier', 'sentBy'])->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->when($search, fn ($q) => $q->where('recipient_email', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%"))->latest()->get();
        return ['Purchase Email Report', ['Purchase Number', 'Supplier', 'Recipient', 'Status', 'Sent By', 'Sent Date', 'Error'], $rows->map(fn ($log) => [$log->purchase?->reference_number, $log->purchase?->supplier?->name, $log->recipient_email, ucfirst($log->status), $log->sentBy?->name, $log->sent_at?->format('Y-m-d H:i'), $log->error_message])->all(), []];
    }

    private function stockValuation(?int $branchId): array
    {
        $rows = collect(app(FinancialReportService::class)->stockValuation($branchId));
        return ['Stock Valuation Report', ['Branch', 'Location', 'Product', 'Category', 'Quantity', 'Average Cost', 'Value'], $rows->map(fn ($row) => [$row['branch'], $row['location'], $row['product'], $row['category'], $row['quantity'], $this->formatCurrency($row['average_cost']), $this->formatCurrency($row['value'])])->all(), ['Total Value' => $this->formatCurrency($rows->sum('value'))]];
    }

    private function profitLoss(?int $branchId, ?string $from, ?string $to): array
    {
        $report = app(FinancialReportService::class)->profitLoss($branchId, $from ?: now()->startOfMonth()->toDateString(), $to ?: today()->toDateString());
        return ['Profit & Loss', ['Metric', 'Amount'], collect($report)->map(fn ($value, $key) => [str($key)->replace('_', ' ')->title()->toString(), $this->formatCurrency($value)])->values()->all(), []];
    }

    private function cashbook(?int $branchId, ?string $from, ?string $to): array
    {
        $rows = CashbookSession::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('session_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('session_date', '<=', $to))->latest()->get();
        return ['Cashbook Report', ['Date', 'Branch', 'Opening', 'Cash In', 'Cash Out', 'Expected', 'Actual', 'Difference'], $rows->map(fn ($s) => [$this->formatDate($s->session_date), $s->branch?->name, $this->formatCurrency($s->opening_cash), $this->formatCurrency($s->cash_in), $this->formatCurrency($s->cash_out), $this->formatCurrency($s->expected_cash), $this->formatCurrency($s->actual_cash), $this->formatCurrency($s->difference)])->all(), []];
    }
}
