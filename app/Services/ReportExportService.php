<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Category;
use App\Models\Customer;
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
use Illuminate\Http\Request;

class ReportExportService
{
    public function getCompanyHeader(Request $request): array
    {
        $settings = \App\Models\Setting::query()->first();
        $branch = $request->integer('branch_id') ? Branch::find($request->integer('branch_id')) : null;

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
            'printed_by' => $request->user()?->name ?? '-',
            'printed_date' => now()->format('Y-m-d H:i'),
        ];
    }

    public function generatePdf(string $reportTitle, array $data, array $filters = []): string
    {
        return app(PdfExportService::class)->generatePdf($reportTitle, ['filters' => $filters, ...$data]);
    }

    public function generateExcel(string $reportTitle, array $data, array $filters = [])
    {
        return app(ExcelExportService::class)->generateExcel(str($reportTitle)->slug('_').'.xls', ['title' => $reportTitle, 'filters' => $filters, ...$data]);
    }

    public function formatCurrency(float|int|string|null $value): string
    {
        return 'TZS '.number_format((float) $value, 2);
    }

    public function formatDate($value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '-';
    }

    public function build(string $key, Request $request): array
    {
        $header = $this->getCompanyHeader($request);
        [$title, $headers, $rows, $totals] = $this->rows($key, $request);

        return compact('title', 'header', 'headers', 'rows', 'totals') + ['filters' => $request->query()];
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
            ->with(['sale.customer', 'sale.soldBy', 'sale.createdBy', 'sale.payments', 'product.unit', 'product.category', 'stockLocation'])
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
                ->whereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('sale_number', 'like', "%{$search}%"))))
            ->latest('id')
            ->get();

        $headers = [$tr('sale_number'), $tr('sale_time'), $tr('product_name'), $tr('sku'), $tr('sale_type'), $tr('customer'), $tr('quantity_sold'), $tr('unit')];

        if ($canViewProfit) {
            array_push($headers, $tr('buying_price_unit'), $tr('total_cost'));
        }

        array_push($headers, $tr('selling_price_unit'), $tr('total_sales'), $tr('discount'));

        if ($canViewProfit) {
            $headers[] = $tr('profit');
        }

        array_push($headers, $tr('cashier'), $tr('stock_location'), $tr('payment_status'), $tr('payment_method'));

        $exportRows = $rows->map(function (SaleItem $item) use ($canViewProfit, $lineCost, $lineProfit, $paymentLabel) {
            $row = [
                $item->sale?->sale_number,
                $item->sale?->created_at?->format('H:i'),
                $item->product?->name,
                $item->product?->sku,
                $item->sale_type === 'wholesale' ? $tr('wholesale') : $tr('retail'),
                $item->sale?->customer?->name ?: $tr('walk_in_customer'),
                number_format((float) $item->quantity, 2),
                $item->product?->unit?->short_name,
            ];

            if ($canViewProfit) {
                array_push($row, $this->formatCurrency($item->unit_cost), $this->formatCurrency($lineCost($item)));
            }

            array_push($row, $this->formatCurrency($item->unit_price), $this->formatCurrency($item->line_total), $this->formatCurrency($item->discount_amount));

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($lineProfit($item));
            }

            array_push(
                $row,
                $item->sale?->soldBy?->name ?? $item->sale?->createdBy?->name,
                $item->sold_from_label ?: $item->stockLocation?->name,
                str($item->sale?->payment_status)->title()->toString(),
                $item->sale?->payments?->pluck('payment_method')->filter()->unique()->map($paymentLabel)->join(', ') ?: '-'
            );

            return $row;
        })->all();

        $totals = [
            $tr('total_sales') => $this->formatCurrency($rows->sum('line_total')),
            $tr('sold_quantity_total') => number_format($rows->sum('quantity'), 2),
            $tr('sales_count') => number_format($rows->pluck('sale_id')->unique()->count()),
            $tr('cash_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'cash')->sum('amount')),
            $tr('credit_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'credit')->sum('amount')),
        ];

        if ($canViewProfit) {
            $totals = [$tr('total_cost') => $this->formatCurrency($rows->sum(fn ($item) => $lineCost($item))), $tr('total_profit') => $this->formatCurrency($rows->sum(fn ($item) => $lineProfit($item))), ...$totals];
        }

        return [$tr('daily_sales_product_details'), $headers, $exportRows, $totals];
    }

    private function purchases(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Purchase::with('supplier')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to))->when($search, fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")))->latest()->get();
        return ['Purchase Report', ['Date', 'Reference', 'Supplier', 'Status', 'Total', 'Paid', 'Balance'], $rows->map(fn ($purchase) => [$this->formatDate($purchase->purchase_date), $purchase->reference_number, $purchase->supplier?->name, ucfirst($purchase->status), $this->formatCurrency($purchase->total_amount), $this->formatCurrency($purchase->paid_amount), $this->formatCurrency($purchase->balance_amount)])->all(), ['Total Purchases' => $this->formatCurrency($rows->sum('total_amount'))]];
    }

    private function expenses(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Expense::with(['category', 'branch', 'paidBy'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))->when($search, fn ($q) => $q->where('description', 'like', "%{$search}%"))->latest()->get();
        return ['Expense Report', ['Date', 'Category', 'Branch', 'Method', 'Paid By', 'Amount'], $rows->map(fn ($expense) => [$this->formatDate($expense->expense_date), $expense->category?->name, $expense->branch?->name, str($expense->payment_method)->replace('_', ' ')->title(), $expense->paidBy?->name, $this->formatCurrency($expense->amount)])->all(), ['Total Expenses' => $this->formatCurrency($rows->sum('amount'))]];
    }

    private function customers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Customer::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($request->string('typeFilter')->toString(), fn ($q, $type) => $q->where('customer_type', $type))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();
        return ['Customer Balance Report', ['Customer', 'Phone', 'Type', 'Branch', 'Credit Limit', 'Outstanding'], $rows->map(fn ($customer) => [$customer->name, $customer->phone, $customer->customer_type, $customer->branch?->name, $this->formatCurrency($customer->credit_limit), $this->formatCurrency($accounting->customerBalance($customer))])->all(), []];
    }

    private function suppliers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Supplier::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();
        return ['Supplier Balance Report', ['Supplier', 'Phone', 'Branch', 'Opening', 'Outstanding'], $rows->map(fn ($supplier) => [$supplier->name, $supplier->phone, $supplier->branch?->name, $this->formatCurrency($supplier->opening_balance), $this->formatCurrency($accounting->supplierBalance($supplier))])->all(), []];
    }

    private function products(Request $request, ?int $branchId, string $search): array
    {
        $rows = Product::with(['category', 'unit', 'branch'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('categoryFilter'), fn ($q, $id) => $q->where('category_id', $id))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))->orderBy('name')->get();
        return ['Products', ['Product', 'SKU', 'Category', 'Unit', 'Buying Price', 'Retail Price', 'Wholesale Price', 'Status'], $rows->map(fn ($p) => [$p->name, $p->sku, $p->category?->name, $p->unit?->short_name, $this->formatCurrency($p->buying_price), $this->formatCurrency($p->selling_price), $this->formatCurrency($p->wholesale_price), ucfirst($p->status)])->all(), []];
    }

    private function storeStock(Request $request, ?int $branchId, string $search): array
    {
        $branchId = $branchId ?: (int) ($request->user()?->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
        $location = StockLocation::where('branch_id', $branchId)->where('type', 'store')->first();
        $inventory = app(InventoryService::class);

        $rows = Product::with(['category', 'unit'])
            ->when($request->integer('categoryFilter'), fn ($query, $id) => $query->where('category_id', $id))
            ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($inventory, $location, $branchId) {
                $quantity = $location ? $inventory->getProductStock($product->id, $location->id, $branchId) : 0;
                $status = $quantity <= 0 ? 'out_of_stock' : ($quantity <= (float) $product->reorder_level ? 'low_stock' : 'in_stock');

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'average_cost' => $location ? $inventory->getAverageCost($product->id, $location->id, $branchId) : 0,
                    'last_received' => $location ? StockMovement::where('product_id', $product->id)->where('stock_location_id', $location->id)->where('movement_type', 'purchase_in')->latest('movement_date')->value('movement_date') : null,
                    'status' => $status,
                ];
            })
            ->when($request->string('statusFilter')->toString(), fn ($collection, $status) => $collection->where('status', $status))
            ->values();

        return [
            'Main Store Stock',
            ['Product', 'SKU', 'Category', 'Unit', 'Store Qty', 'Avg Cost', 'Last Received', 'Reorder', 'Status'],
            $rows->map(fn ($row) => [
                $row['product']->name,
                $row['product']->sku,
                $row['product']->category?->name,
                $row['product']->unit?->short_name,
                number_format($row['quantity'], 2),
                $this->formatCurrency($row['average_cost']),
                $this->formatDate($row['last_received']),
                number_format((float) $row['product']->reorder_level, 2),
                str($row['status'])->replace('_', ' ')->title()->toString(),
            ])->all(),
            [
                'Total Store Qty' => number_format($rows->sum('quantity'), 2),
                'Total Stock Value' => $this->formatCurrency($rows->sum(fn ($row) => $row['quantity'] * $row['average_cost'])),
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
        $rows = StockMovement::with(['product', 'stockLocation', 'createdBy'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('stock_location_id'), fn ($q, $id) => $q->where('stock_location_id', $id))->when($from, fn ($q) => $q->whereDate('movement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('movement_date', '<=', $to))->when($search, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))->latest()->get();
        return ['Stock Movement Report', ['Date', 'Product', 'Location', 'Type', 'Quantity', 'Cost', 'Created By'], $rows->map(fn ($m) => [$this->formatDate($m->movement_date), $m->product?->name, $m->stockLocation?->name, $m->movement_type, $m->quantity, $this->formatCurrency($m->unit_cost), $m->createdBy?->name])->all(), []];
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
