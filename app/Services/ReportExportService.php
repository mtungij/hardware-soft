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
            'reports.purchases', 'tables.purchases' => $this->purchases($request, $branchId, $from, $to, $search),
            'reports.expenses', 'tables.expenses' => $this->expenses($request, $branchId, $from, $to, $search),
            'reports.customers', 'tables.customers' => $this->customers($request, $branchId, $search),
            'reports.suppliers', 'tables.suppliers' => $this->suppliers($request, $branchId, $search),
            'reports.stock-valuation', 'tables.inventory-summary' => $this->stockValuation($branchId),
            'reports.profit-loss' => $this->profitLoss($branchId, $from, $to),
            'reports.cashbook', 'tables.cashbook' => $this->cashbook($branchId, $from, $to),
            'tables.products' => $this->products($request, $branchId, $search),
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
        $rows = Product::with(['category', 'unit', 'branch'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('categoryFilter'), fn ($q, $id) => $q->where('category_id', $id))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))->latest()->get();
        return ['Products', ['Product', 'SKU', 'Category', 'Unit', 'Buying Price', 'Retail Price', 'Wholesale Price', 'Status'], $rows->map(fn ($p) => [$p->name, $p->sku, $p->category?->name, $p->unit?->short_name, $this->formatCurrency($p->buying_price), $this->formatCurrency($p->selling_price), $this->formatCurrency($p->wholesale_price), ucfirst($p->status)])->all(), []];
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
