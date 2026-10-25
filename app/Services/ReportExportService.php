<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuthorizationScope;
use App\Support\BranchAccess;
use App\Support\ExpenseAccess;
use App\Support\InventorySettings;
use App\Support\NumberFormatter;
use App\Support\PosSaleDate;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportExportService
{
    public function getCompanyHeader(Request $request): array
    {
        $settings = Setting::query()->where('company_id', $request->user()->company_id)->first();
        $branchId = BranchAccess::resolve($request->user(), $request->integer('branch_id') ?: $request->integer('branchFilter') ?: null);
        $locationId = $request->integer('stock_location_id');
        $branch = $branchId ? Branch::find($branchId) : null;
        $location = $locationId ? BranchAccess::scopeAccessibleToUser(StockLocation::query(), $request->user())->findOrFail($locationId) : null;

        return [
            'company_name' => $settings?->company_name ?: config('app.name', 'HARDEX ERP'),
            'logo' => $settings?->company_logo ? storage_path('app/public/'.$settings->company_logo) : null,
            'phone' => $settings?->company_phone,
            'whatsapp' => $settings?->whatsapp_number,
            'email' => $settings?->company_email,
            'address' => $settings?->company_address,
            'region' => $settings?->region,
            'district' => $settings?->district,
            'date_range' => trim(($request->string('date_from')->toString() ?: __('application.beginning_4373e9d7')).' '.UiText::translate('to').' '.($request->string('date_to')->toString() ?: today()->toDateString())),
            'branch_name' => $branch?->name ?? __('application.all_branches_0bf51d9a'),
            'stock_location' => $location?->name ?? __('application.all_locations_3094bd38'),
            'printed_by' => $request->user()?->name ?? '-',
            'printed_date' => now()->format('Y-m-d H:i'),
        ];
    }

    public function generatePdf(string $reportTitle, array $data, array $filters = []): string
    {
        if (($data['export_key'] ?? null) === 'tables.products') {
            $data['headers'] = [__('application.s_n_497c2c9c'), ...$data['headers']];
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
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }

    public function build(string $key, Request $request): array
    {
        $this->authorizeExport($key, $request);
        $header = $this->getCompanyHeader($request);
        [$title, $headers, $rows, $totals] = $this->rows($key, $request);

        $title = UiText::translate($title);
        $headers = array_map(UiText::translate(...), $headers);
        $totals = collect($totals)->mapWithKeys(fn ($value, $label) => [UiText::translate($label) => $value])->all();

        return compact('title', 'header', 'headers', 'rows', 'totals') + [
            'filters' => $request->query(),
            'export_key' => $key,
        ];
    }

    private function rows(string $key, Request $request): array
    {
        $branchId = BranchAccess::resolve($request->user(), $request->integer('branch_id') ?: $request->integer('branchFilter') ?: null);
        $from = $request->string('date_from')->toString() ?: $request->string('created_from')->toString();
        $to = $request->string('date_to')->toString() ?: $request->string('created_to')->toString();
        $search = $request->string('search')->toString();

        return match ($key) {
            'reports.internal-sales' => $this->internalSales($request, $branchId, $from, $to),
            'reports.sales', 'tables.sales' => $this->sales($request, $branchId, $from, $to, $search),
            'tables.sales-items' => $this->salesItems($request, $branchId, $from, $to, $search),
            'reports.purchases', 'tables.purchases' => $this->purchases($request, $branchId, $from, $to, $search),
            'reports.expenses', 'tables.expenses' => $this->expenses($request, $branchId, $from, $to, $search),
            'reports.customer-payments', 'tables.customer-payments' => $this->customerPayments($request, $branchId, $from, $to),
            'reports.customers', 'tables.customers' => $this->customers($request, $branchId, $search),
            'reports.suppliers', 'tables.suppliers' => $this->suppliers($request, $branchId, $search),
            'reports.stock-valuation', 'tables.inventory-summary' => $this->stockValuation($branchId, $request->integer('stock_location_id') ?: null, $search),
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

    private function internalSales(Request $request, ?int $branchId, ?string $from, ?string $to): array
    {
        $user = $request->user();
        $tab = $request->string('tab')->toString() ?: 'outgoing';
        abort_unless(in_array($tab, ['outgoing', 'incoming', 'location'], true), 404);
        $report = app(InternalSaleReportService::class);
        $filters = [
            'date_from' => $from ?: now()->startOfMonth()->toDateString(),
            'date_to' => $to ?: today()->toDateString(),
            'branch_id' => $branchId,
            'from_location_id' => $request->integer('from_location_id') ?: null,
            'to_location_id' => $request->integer('to_location_id') ?: null,
            'product_id' => $request->integer('product_id') ?: null,
            'status' => $request->string('status')->toString() ?: 'completed',
            'number' => $request->string('number')->toString(),
        ];
        if ($tab === 'outgoing') {
            $lines = $report->rows($user, 'outgoing', $filters);
            $canCost = $user->can('internal_sales.view_cost');
            $canMargin = $user->can('internal_sales.view_margin');
            $headers = [__('application.date_eb9a4bc1'), __('application.internal_sale_9d355b80'), __('application.destination_d4271349'), __('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('application.quantity_44f6af69'), __('application.internal_price_6952f6e5'), __('application.internal_sales_value_1f1e708b')];
            if ($canCost) {
                $headers[] = __('application.internal_cost_632d656a');
            }
            if ($canMargin) {
                $headers[] = __('application.internal_profit_9b602e26');
            }
            $headers[] = __('application.status_bae7d5be');
            $rows = $lines->map(function (array $line) use ($canCost, $canMargin): array {
                $row = [$line['date']?->format('Y-m-d'), $line['number'], $line['destination'], $line['product'],
                    $line['sku'], $line['transaction_unit'], $line['transaction_quantity'],
                    $line['posted'] ? $line['internal_unit_price'] : null, $line['posted'] ? $line['internal_value'] : null];
                if ($canCost) {
                    $row[] = $line['posted'] ? $line['source_cost'] : null;
                }
                if ($canMargin) {
                    $row[] = $line['posted'] ? $line['internal_margin'] : null;
                }
                $row[] = UiText::status($line['status']);

                return $row;
            })->all();
            $summary = $report->totals($lines);
            $totals = [__('application.internal_sales_a7596208') => $summary['internal_value'], __('application.completed_internal_sales_ad1ee940') => $summary['sales']];
            if ($canCost) {
                $totals[__('application.internal_cost_632d656a')] = $summary['source_cost'];
            }
            if ($canMargin) {
                $totals[__('application.internal_profit_9b602e26')] = $summary['internal_margin'];
            }

            return [__('application.outgoing_internal_sales_80587025'), $headers, $rows, $totals];
        }

        if ($tab === 'incoming') {
            $lines = $report->rows($user, 'incoming', $filters);
            $headers = [__('application.date_eb9a4bc1'), __('application.internal_sale_9d355b80'), __('application.source_6da13add'), __('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('application.quantity_44f6af69'), __('application.acquisition_price_9f0f605b'), __('application.acquisition_value_177290e3'), __('application.status_bae7d5be')];
            $rows = $lines->map(fn (array $line): array => [
                $line['date']?->format('Y-m-d'), $line['number'], $line['source'], $line['product'], $line['sku'],
                $line['transaction_unit'], $line['transaction_quantity'], $line['posted'] ? $line['internal_unit_price'] : null,
                $line['posted'] ? $line['acquisition_value'] : null, UiText::status($line['status']),
            ])->all();
            $summary = $report->totals($lines);

            return [__('application.internal_purchases_and_acquisitions_90df0d7f'), $headers, $rows, [
                __('application.acquisition_value_177290e3') => $summary['acquisition_value'], __('application.received_base_quantity_909148cf') => $summary['base_quantity'],
                __('application.internal_receipts_08846fd6') => $summary['sales'],
            ]];
        }

        abort_unless($user->can('reports.location_margins') && $user->can('stock.view_value')
            && $user->can('internal_sales.view_margin'), 403);
        $outgoing = $report->locationRows($user, 'outgoing', $filters)->where('posted', true);
        $incoming = $report->locationRows($user, 'incoming', $filters)->where('posted', true);
        $customer = $report->customerProductRows($user, $filters);
        $financial = app(FinancialReportService::class);
        $scope = AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH);
        $effectiveBranch = $branchId ?: ($scope === AuthorizationScope::COMPANY ? null : (int) $user->branch_id);
        $locations = $financial->valuationLocations($effectiveBranch)
            ->filter(fn (StockLocation $location) => (! $filters['from_location_id'] && ! $filters['to_location_id'])
                || $location->id === $filters['from_location_id'] || $location->id === $filters['to_location_id']);
        $headers = [__('application.location_d219c681'), __('application.internal_sales_a7596208'), __('application.internal_cost_632d656a'), __('application.internal_profit_9b602e26'), __('application.stock_bought_internally_04bdead4'),
            __('application.customer_sales_a93a5751'), __('application.cost_of_sold_stock_e0981624'), __('application.outlet_profit_f6a0f4dd')];
        $rows = $locations->map(function (StockLocation $location) use ($report, $outgoing, $incoming, $customer): array {
            $source = $report->totals($outgoing->where('source_id', $location->id));
            $receipts = $report->totals($incoming->where('destination_id', $location->id));
            $sold = $customer->where('location_id', $location->id);
            $sales = $sold->sum('customer_sales');
            $cost = $sold->sum('acquisition_cost');

            return [$location->name, $source['internal_value'], $source['source_cost'], $source['internal_margin'],
                $receipts['acquisition_value'], $sales, $cost, $sales - $cost];
        })->values()->all();

        return [__('application.location_profitability_043595c7'), $headers, $rows, []];
    }

    private function sales(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $canViewCost = ($request->user()?->can('sales.view_cost') || $request->user()?->can('sales.view_profit')) ?? false;
        $rows = AuthorizationScope::reports(Sale::query(), $request->user())
            ->with(['customer', 'soldBy', 'createdBy', 'items.stockLocation', 'items.product', 'items.sellingUnit', 'items.baseUnit'])
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

        $recordingTimezone = $request->user()->company_id ? app(PosSaleDate::class)->timezone($request->user()->company_id) : config('app.timezone');
        $exportRows = $rows->map(function (Sale $sale) use ($canViewCost): array {
            $transactionQuantities = $sale->items->map(fn (SaleItem $item) => $item->product?->displayName().' '.$this->formatQuantity($item->quantity).' '.($item->selling_unit_code_snapshot ?: $item->sellingUnit?->short_name))->join("\n");
            $baseQuantities = $sale->items->map(fn (SaleItem $item) => $item->product?->displayName().' '.$this->formatQuantity($item->base_quantity ?: $item->quantity).' '.($item->base_unit_code_snapshot ?: $item->baseUnit?->short_name))->join("\n");
            $cogs = $sale->items->sum(fn (SaleItem $item) => $item->base_unit_cost !== null
                ? (float) $item->base_quantity * (float) $item->base_unit_cost
                : (float) $item->quantity * (float) $item->unit_cost);

            $row = [$sale->sale_number, $transactionQuantities, $baseQuantities, $sale->soldBy?->name ?? $sale->createdBy?->name, $sale->stockLocationLabel(), $sale->saleTypeLabel(), $this->formatCurrency($sale->total_amount)];

            if ($canViewCost) {
                array_push($row, $this->formatCurrency($cogs), $this->formatCurrency((float) $sale->total_amount - $cogs));
            }

            return [...$row, $this->formatDate($sale->sale_date), $sale->customer?->displayName() ?? __('application.walk_in_801d866b'), $this->formatCurrency($sale->paid_amount), $this->formatCurrency($sale->balance_amount), $sale->created_at?->clone()->timezone(app(PosSaleDate::class)->timezone($sale->company_id))->format('Y-m-d H:i:s')];
        })->all();

        $headers = [__('application.sale_number_1b4a02ea'), __('application.transaction_quantities_75c6aa8a'), __('application.base_quantities_e5055f98'), __('application.user_9f8a2389'), __('application.stock_location_50e3c4fe'), __('application.sale_type_76caf1bd'), __('application.amount_43dc8532')];

        if ($canViewCost) {
            array_push($headers, __('application.cogs_7e86884c'), __('application.gross_profit_11f53e51'));
        }

        return [__('application.sales_report_0f89ab2f'), [...$headers, __('application.date_eb9a4bc1'), __('application.customer_0e85749a'), __('application.paid_dc9d4584'), __('application.balance_90eef613'), __('sale_dates.recorded_at')], $exportRows, [__('application.total_amount_490aaabf') => $this->formatCurrency($rows->sum('total_amount'))]];
    }

    private function salesItems(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $canViewProfit = $request->user()?->can('sales.view_profit') ?? false;
        $assignedLocationIds = AuthorizationScope::scopeFor($request->user(), 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) === AuthorizationScope::ASSIGNED_LOCATIONS
            ? AuthorizationScope::stockLocationIds($request->user())
            : null;
        $lineCost = fn (SaleItem $item): float => $item->base_unit_cost !== null
            ? (float) $item->base_quantity * (float) $item->base_unit_cost
            : (float) $item->quantity * (float) $item->unit_cost;
        $lineProfit = fn (SaleItem $item): float => (float) $item->line_total - $lineCost($item);
        $tr = fn (string $key): string => __('messages.sales_items.'.$key);
        $paymentLabel = fn (string $method): string => $tr('method_'.$method);

        $rows = SaleItem::query()
            ->with(['sale.customer', 'sale.soldBy', 'sale.createdBy', 'sale.payments', 'product.unit', 'product.category', 'product.size', 'sellingUnit', 'stockLocation'])
            ->whereHas('sale', function ($query) use ($request, $branchId, $from, $to) {
                AuthorizationScope::reports($query, $request->user())
                    ->where('status', 'completed')
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                    ->when($request->integer('cashier_id'), fn ($q, $id) => $q->where(fn ($cashierQuery) => $cashierQuery->where('sold_by', $id)->orWhere('created_by', $id)))
                    ->when($request->string('payment_method')->toString(), fn ($q, $method) => $q->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('payment_method', $method)))
                    ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to));
            })
            ->when($request->string('sale_type')->toString(), fn ($query, $type) => $query->where('sale_type', $type))
            ->when($assignedLocationIds !== null, fn ($query) => $query->whereIn('stock_location_id', $assignedLocationIds))
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
                $sales = (float) $items->sum('line_total');

                return [
                    'sale' => $sale,
                    'items' => $items->values(),
                    'customer' => $sale?->customer?->displayName() ?: $tr('walk_in_customer'),
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

        $headers = [$tr('sale_no'), $tr('sale_time'), $tr('customer'), $tr('product_name'), $tr('sku'), $tr('quantity_sold'), $tr('unit')];

        if ($canViewProfit) {
            $headers[] = $tr('buying_price_unit');
        }

        $headers[] = $tr('selling_price_unit');

        if ($canViewProfit) {
            $headers[] = $tr('total_cost');
        }

        $headers[] = $tr('total_sales');

        if ($canViewProfit) {
            $headers[] = $tr('profit');
        }

        array_push($headers, $tr('sale_type'), $tr('payment_method'), $tr('stock_location'), $tr('cashier'));

        $exportRows = $rows->map(function (SaleItem $item) use ($canViewProfit, $lineCost, $lineProfit, $paymentLabel, $tr): array {
            $sale = $item->sale;
            $paymentMethods = $sale?->payments?->pluck('payment_method')->filter()->unique()->values() ?? collect();
            $method = $sale?->payment_status === 'partial'
                ? $tr('partial')
                : ($paymentMethods->count() > 1 ? $tr('mixed') : ($paymentMethods->isEmpty() ? '-' : $paymentLabel((string) $paymentMethods->first())));
            $row = [
                $sale?->sale_number,
                $sale?->created_at?->format('H:i'),
                $sale?->customer?->displayName() ?: $tr('walk_in_customer'),
                $item->productDisplayNameWithSize(),
                $item->product?->sku,
                $this->formatQuantity($item->quantity),
                $item->selling_unit_code_snapshot ?: ($item->selling_unit_name_snapshot ?: $item->sellingUnit?->short_name),
            ];

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($item->unit_cost);
            }

            $row[] = $this->formatCurrency($item->unit_price);

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($lineCost($item));
            }

            $row[] = $this->formatCurrency($item->line_total);

            if ($canViewProfit) {
                $row[] = $this->formatCurrency($lineProfit($item));
            }

            array_push(
                $row,
                $item->sale_type === 'wholesale' ? $tr('wholesale') : $tr('retail'),
                $method,
                $item->sold_from_label ?: $item->stockLocation?->name,
                $sale?->soldBy?->name ?? $sale?->createdBy?->name,
            );

            return $row;
        })->all();

        $totals = [
            $tr('total_sales') => $this->formatCurrency($rows->sum('line_total')),
            $tr('total_customers') => number_format($invoiceRows->pluck('sale.customer_id')->filter()->unique()->count() + ($invoiceRows->contains(fn ($row) => blank($row['sale']?->customer_id)) ? 1 : 0)),
            $tr('total_invoices') => number_format($rows->pluck('sale_id')->unique()->count()),
            $tr('total_products_sold') => $this->formatQuantity($rows->sum('quantity')),
            $tr('cash_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'cash')->sum('amount')),
            $tr('credit_sales_total') => $this->formatCurrency(SalePayment::whereIn('sale_id', $rows->pluck('sale_id')->unique())->where('payment_method', 'credit')->sum('amount')),
        ];

        if ($canViewProfit) {
            $totals = [$tr('total_cost') => $this->formatCurrency($rows->sum(fn (SaleItem $item) => $lineCost($item))), $tr('total_profit') => $this->formatCurrency($rows->sum(fn (SaleItem $item) => $lineProfit($item))), ...$totals];
        }

        return [$tr('todays_sales_summary'), $headers, $exportRows, $totals];
    }

    private function authorizeExport(string $key, Request $request): void
    {
        if (in_array($key, ['tables.expenses', 'reports.expenses'], true)) {
            ExpenseAccess::authorizeExport($request->user());

            return;
        }

        $permission = match (true) {
            $key === 'reports.internal-sales' => 'reports.export',
            $key === 'tables.products' => 'products.view',
            in_array($key, ['tables.store-stock', 'tables.stock-movements', 'tables.stock-transfers'], true) => 'stock.view',
            $key === 'tables.users' => 'users.view',
            str_contains($key, 'profit-loss') => 'reports.profit',
            str_contains($key, 'stock-valuation'), str_contains($key, 'inventory-summary') => 'reports.stock',
            str_contains($key, 'purchase'), str_contains($key, 'supplier') => 'reports.purchases',
            str_contains($key, 'expense') => 'reports.expenses',
            str_contains($key, 'sale') => 'reports.sales',
            default => 'reports.export',
        };

        abort_unless($request->user()?->can('reports.export') && $request->user()?->can($permission), 403);
    }

    private function purchases(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = Purchase::with(['supplier', 'items.product', 'items.purchaseUnit', 'items.stockUnit'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to))->when($search, fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")))->latest()->get();

        $exportRows = $rows->map(function (Purchase $purchase): array {
            $ordered = $purchase->items->map(fn ($item) => $item->product?->displayName().' '.$this->formatQuantity($item->ordered_quantity).' '.($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name))->join("\n");
            $conversions = $purchase->items->map(fn ($item) => '1 '.($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name).' = '.$this->formatQuantity($item->purchaseFactor()).' '.($item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name))->join("\n");
            $baseOrdered = $purchase->items->map(fn ($item) => $this->formatQuantity($item->base_ordered_quantity ?? $item->stockQuantity((float) $item->ordered_quantity)).' '.($item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name))->join("\n");
            $received = $purchase->items->map(fn ($item) => $this->formatQuantity($item->received_quantity).' '.($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name).' / '.$this->formatQuantity($item->base_received_quantity ?? $item->stockQuantity((float) $item->received_quantity)).' '.($item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name))->join("\n");
            $remaining = $purchase->items->map(fn ($item) => $this->formatQuantity($item->remainingQuantity()).' '.($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name))->join("\n");

            return [$this->formatDate($purchase->purchase_date), $purchase->reference_number, $purchase->supplier?->name, $ordered, $conversions, $baseOrdered, $received, $remaining, UiText::status($purchase->status), $this->formatCurrency($purchase->total_amount), $this->formatCurrency($purchase->paid_amount), $this->formatCurrency($purchase->balance_amount)];
        })->all();

        return [__('application.purchase_report_4edd5c16'), [__('application.date_eb9a4bc1'), __('application.reference_db1c7845'), __('application.supplier_55edd462'), __('application.ordered_qty_b903ee79'), __('application.conversion_9151f843'), __('application.base_qty_ordered_1539aff2'), __('application.received_base_received_c7cc46af'), __('application.remaining_qty_b5a73f44'), __('application.status_bae7d5be'), __('application.total_b25928c6'), __('application.paid_dc9d4584'), __('application.balance_90eef613')], $exportRows, [__('application.total_purchases_6231e5c8') => $this->formatCurrency($rows->sum('total_amount'))]];
    }

    private function expenses(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = ExpenseAccess::query($request->user())->with(['category', 'branch', 'paidBy'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->when($search, fn ($q) => $q->where(fn ($query) => $query
                ->where('reference_number', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->get();

        return [__('application.expense_report_285d669e'), [__('application.date_eb9a4bc1'), __('application.category_a3c686e7'), __('application.branch_1627510b'), __('application.method_88306943'), __('application.paid_by_2e06e8e0'), __('application.amount_43dc8532')], $rows->map(fn ($expense) => [$this->formatDate($expense->expense_date), $expense->category?->name, $expense->branch?->name, UiText::label($expense->payment_method), $expense->paidBy?->name, $this->formatCurrency($expense->amount)])->all(), [__('application.total_expenses_750134b6') => $this->formatCurrency($rows->sum('amount'))]];
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
            __('application.customer_payment_report_1fa3b1d2'),
            [__('application.date_eb9a4bc1'), __('application.time_6c82e6dd'), __('application.receipt_number_37553f8e'), __('application.customer_0e85749a'), __('application.phone_77064d52'), __('application.amount_43dc8532'), __('application.method_88306943'), __('application.reference_db1c7845'), __('application.branch_1627510b'), __('application.received_by_f84f73c6'), __('application.notes_70440046')],
            $rows->map(fn ($payment) => [
                $this->formatDate($payment->payment_date),
                $payment->created_at?->format('H:i'),
                $payment->receipt_number ?: 'PAY-'.$payment->id,
                $payment->customer?->displayName(),
                $payment->customer?->phone,
                $this->formatCurrency($payment->amount),
                UiText::label($payment->payment_method),
                $payment->reference_number,
                $payment->branch?->name,
                $payment->receivedBy?->name,
                $payment->notes,
            ])->all(),
            [
                __('application.cash_payments_49416736') => $this->formatCurrency($rows->where('payment_method', 'cash')->sum('amount')),
                __('application.mobile_money_payments_e2dfddd5') => $this->formatCurrency($rows->where('payment_method', 'mobile_money')->sum('amount')),
                __('application.bank_payments_b8d4227f') => $this->formatCurrency($rows->where('payment_method', 'bank')->sum('amount')),
                __('application.total_payments_53b493df') => $this->formatCurrency($rows->sum('amount')),
            ],
        ];
    }

    private function customers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Customer::with('branch')->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($request->string('typeFilter')->toString(), fn ($q, $type) => $q->where('customer_type', $type))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();

        return [__('application.customer_balance_report_2f0b407b'), [__('application.customer_0e85749a'), __('application.phone_77064d52'), __('application.type_3deb7456'), __('application.branch_1627510b'), __('application.outstanding_f8ee57ec')], $rows->map(fn ($customer) => [$customer->name, $customer->phone, $customer->customer_type, $customer->branch?->name, $this->formatCurrency($accounting->customerBalance($customer))])->all(), []];
    }

    private function suppliers(Request $request, ?int $branchId, string $search): array
    {
        $accounting = app(AccountingService::class);
        $rows = Supplier::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->latest()->get();

        return [__('application.supplier_balance_report_8964c2d2'), [__('application.supplier_55edd462'), __('application.phone_77064d52'), __('application.branch_1627510b'), __('application.opening_56e44065'), __('application.outstanding_f8ee57ec')], $rows->map(fn ($supplier) => [$supplier->name, $supplier->phone, $supplier->branch?->name, $this->formatCurrency($supplier->opening_balance), $this->formatCurrency($accounting->supplierBalance($supplier))])->all(), []];
    }

    private function products(Request $request, ?int $branchId, string $search): array
    {
        $canViewBuyingPrice = $request->user()?->can('products.view_buying_price') ?? false;
        $canViewSellingPrice = $request->user()?->can('products.view_selling_price') ?? false;
        $rows = Product::with(['category', 'measurementType', 'unit', 'branch', 'size'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($request->integer('categoryFilter'), fn ($q, $id) => $q->where('category_id', $id))->when($request->string('statusFilter')->toString(), fn ($q, $status) => $q->where('status', $status))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%")))->orderBy('name')->get();

        $inventory = app(InventoryService::class);

        $mainBranchId = (int) (
            $request->user()?->branch_id
            ?: Branch::where('code', 'MAIN')->value('id')
        );

        $stockByProduct = $rows->mapWithKeys(function (Product $product) use ($inventory, $mainBranchId): array {
            $productBranchId = (int) ($product->branch_id ?: $mainBranchId);

            return [
                $product->id => $inventory->getProductTotalStock(
                    $product->id,
                    $productBranchId
                ),
            ];
        });

        $hasSizes = $rows->contains(fn (Product $product) => filled($product->sizeLabel()));
        $headers = [__('application.product_dd3b86d1'), __('application.measurement_type_bbc2cb53')];

        if ($hasSizes) {
            $headers[] = __('application.size_b7152342');
        }

        array_push($headers, __('application.sku_d59192b9'), __('application.category_a3c686e7'), __('application.unit_f6b935ab'));
        if ($canViewBuyingPrice) {
            $headers[] = __('application.buying_price_a46c40be');
        }
        if ($canViewSellingPrice) {
            array_push($headers, __('application.retail_price_d3e361ae'), __('application.wholesale_price_7ecac3d9'));
        }
        $headers[] = __('application.status_bae7d5be');

        $exportRows = $rows->map(function (Product $product) use ($hasSizes, $canViewBuyingPrice, $canViewSellingPrice): array {
            $row = [
                $product->displayName(),
                $product->measurementType?->name ?? str($product->measurementCode())->title()->toString(),
            ];

            if ($hasSizes) {
                $row[] = $product->sizeLabel() ?: '—';
            }

            array_push($row, $product->sku, $product->category?->name, $product->unit?->short_name);
            if ($canViewBuyingPrice) {
                $row[] = $this->formatCurrency($product->buying_price);
            }
            if ($canViewSellingPrice) {
                array_push($row, $this->formatCurrency($product->selling_price), $this->formatCurrency($product->wholesale_price));
            }
            $row[] = UiText::status($product->status);

            return $row;
        })->all();

        $totalProducts = $rows->count();

        $totalStockQuantity = $rows->sum(
            fn (Product $product) => (float) ($stockByProduct[$product->id] ?? 0)
        );

        $totalBuyingValue = $rows->sum(
            fn (Product $product) => (float) ($stockByProduct[$product->id] ?? 0)
                * (float) $product->buying_price
        );

        $totalSellingValue = $rows->sum(
            fn (Product $product) => (float) ($stockByProduct[$product->id] ?? 0)
                * (float) $product->selling_price
        );

        $summary = [
            __('application.total_products_c48f2bf5') => number_format($totalProducts),
            __('application.total_stock_quantity_9be3b41b') => $this->formatQuantity($totalStockQuantity),
        ];

        if ($canViewBuyingPrice) {
            $summary[__('application.total_buying_value_8b2702d9')] = $this->formatCurrency($totalBuyingValue);
        }

        if ($canViewSellingPrice) {
            $summary[__('application.total_selling_value_2408e559')] = $this->formatCurrency($totalSellingValue);
        }

        if ($canViewBuyingPrice && $canViewSellingPrice) {
            $summary[__('application.expected_profit_fe6f484b')] = $this->formatCurrency(
                $totalSellingValue - $totalBuyingValue
            );
        }

        return [__('application.products_fe0a091f'), $headers, $exportRows, $summary];
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
        $setting = InventorySettings::current();

        if (($setting->inventory_mode ?? 'multi_location') === 'single_location' && $setting->default_stock_location_id) {
            $locationId = (int) $setting->default_stock_location_id;
        }

        $locationQuery = StockLocation::query()
            ->whereIn('id', AuthorizationScope::stockLocationIds($user))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        $allowedLocationIds = $locationQuery->pluck('id')->all();
        $stockExpression = "SUM(CASE WHEN stock_movements.quantity_in <> 0 OR stock_movements.quantity_out <> 0 THEN stock_movements.quantity_in - stock_movements.quantity_out WHEN stock_movements.movement_type IN ('sale_out','transfer_out','adjustment_out','damage_out','purchase_receipt_reversal') THEN -stock_movements.quantity ELSE stock_movements.quantity END)";
        $canViewValue = $user?->can('stock.view_value') ?? false;
        $costNumerator = $canViewValue ? "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','internal_sale_in','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) * stock_movements.unit_cost ELSE 0 END)" : '0';
        $costDenominator = $canViewValue ? "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','internal_sale_in','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) ELSE 0 END)" : '0';
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

        $rows = DB::table('products')->whereIn('products.id', Product::query()->select('id'))
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
                'products.id as product_id',
                'stock_locations.id as stock_location_id',
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

        $ledger = app(StockLedgerReadService::class);
        $latestByPair = $ledger->latestForRows($rows, $user);
        $isPdf = $request->route('format') === 'pdf';
        $headers = $isPdf
            ? [__('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('application.location_d219c681'), __('application.current_qty_af8b5c5f'), __('application.latest_action_4edbbdbb'), __('application.before_74f39697'), __('application.change_64fbd995'), __('application.reference_db1c7845'), __('application.action_date_4f24494b')]
            : [__('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.category_a3c686e7'), __('application.unit_f6b935ab'), __('application.stock_location_50e3c4fe'), __('application.location_type_58cd45f1'), __('application.current_qty_af8b5c5f'), __('application.latest_action_4edbbdbb'), __('application.before_74f39697'), __('application.change_64fbd995'), __('application.reference_db1c7845'), __('application.action_date_4f24494b')];
        if ($canViewValue) {
            array_push($headers, __('application.average_cost_128a1828'), __('application.stock_value_c2267f33'));
        }
        if (! $isPdf) {
            $headers[] = __('application.reorder_level_59ef31e3');
        }
        $headers[] = __('application.status_bae7d5be');

        $exportRows = $rows->map(function ($row) use ($canViewValue, $isPdf, $latestByPair, $ledger): array {
            $latest = $latestByPair[$ledger->key($row->product_id, $row->stock_location_id)];
            $details = [
                $this->formatQuantity($row->quantity),
                $latest['action'],
                $latest['before'] === null ? '-' : $this->formatQuantity($latest['before']),
                $latest['change'] === null ? '-' : (($latest['change'] > 0 ? '+' : '').$this->formatQuantity($latest['change'])),
                $latest['reference'],
                $latest['date']?->format('d M Y') ?? '-',
            ];
            $data = $isPdf
                ? [$row->product_name, $row->sku, $row->unit_name, $row->location_name, ...$details]
                : [$row->product_name, $row->sku, $row->category_name, $row->unit_name, $row->location_name, UiText::label($row->location_type), ...$details];
            if ($canViewValue) {
                array_push($data, $this->formatCurrency($row->average_cost), $this->formatCurrency($row->stock_value));
            }
            if (! $isPdf) {
                $data[] = $this->formatQuantity($row->reorder_level);
            }
            $data[] = UiText::label($row->stock_status);

            return $data;
        })->all();

        return [
            $request->boolean('grouped') ? __('application.stock_by_location_grouped_by_product_72e12d84') : __('application.stock_by_location_9a177d3f'),
            $headers,
            $exportRows,
            array_filter([
                __('application.total_quantity_c06411e1') => $this->formatQuantity($rows->sum('quantity')),
                __('application.total_stock_value_bcb64e83') => $canViewValue ? $this->formatCurrency($rows->sum('stock_value')) : null,
                __('application.active_locations_6cd2ac25') => count($allowedLocationIds),
                __('application.latest_actions_614766d0') => __('application.shown_separately_for_each_product_and_location_d9398c87'),
            ], fn ($value) => $value !== null),
        ];
    }

    private function categories(Request $request, ?int $branchId, string $search): array
    {
        $rows = Category::with('branch')->withCount('products')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))->latest()->get();

        return [__('application.categories_6ccb6007'), [__('application.name_709a2322'), __('application.code_adac6937'), __('application.branch_1627510b'), __('application.products_fe0a091f'), __('application.status_bae7d5be')], $rows->map(fn ($c) => [$c->name, $c->code, $c->branch?->name, $c->products_count, UiText::status($c->status)])->all(), []];
    }

    private function units(Request $request, ?int $branchId, string $search): array
    {
        $rows = Unit::with('measurementType')
            ->withCount('products')
            ->when($request->integer('measurementTypeFilter'), fn ($query, $id) => $query->where('measurement_type_id', $id))
            ->when($request->string('statusFilter')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%"))
            ->latest()
            ->get();

        return [__('application.units_12748281'), [__('application.name_709a2322'), __('application.short_name_da4db97e'), __('application.measurement_type_bbc2cb53'), __('application.description_55f8ebc8'), __('application.products_fe0a091f'), __('application.status_bae7d5be')], $rows->map(fn ($unit) => [$unit->name, $unit->short_name, $unit->measurementType?->name, $unit->description, $unit->products_count, UiText::status($unit->status)])->all(), []];
    }

    private function users(Request $request, string $search): array
    {
        $rows = User::with(['branch', 'company', 'roles'])->when(BranchAccess::restricted(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))->when($search, fn ($q) => $q->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))->latest()->get();

        return [__('application.users_57f2b181'), [__('application.name_709a2322'), __('application.email_84add5b2'), __('application.phone_77064d52'), __('application.roles_47dcc27d'), __('application.sales_location_c04a064d'), __('application.company_7a199499'), __('application.branch_1627510b'), __('application.status_bae7d5be')], $rows->map(fn ($u) => [$u->name, $u->email, $u->phone, $u->roles->pluck('name')->join(', '), $u->sales_location_access, $u->company?->company_name, $u->branch?->name, UiText::status($u->status)])->all(), []];
    }

    private function branches(string $search): array
    {
        $rows = Branch::withCount('users')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('region', 'like', "%{$search}%"))
            ->latest()
            ->get();

        return [__('application.branches_f5782279'), [__('application.branch_1627510b'), __('application.code_adac6937'), __('application.phone_77064d52'), __('application.email_84add5b2'), __('application.region_0f217179'), __('application.district_c0cb139c'), __('application.users_57f2b181'), __('application.status_bae7d5be')], $rows->map(fn ($branch) => [$branch->name, $branch->code, $branch->phone, $branch->email, $branch->region, $branch->district, $branch->users_count, UiText::status($branch->status)])->all(), []];
    }

    private function stockMovements(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $canViewCost = $request->user()?->can('stock.view_value') ?? false;
        $rows = app(FinancialReportService::class)->stockMovementsForLocations($branchId)->with(['product.size', 'stockLocation', 'creator'])->when($request->boolean('receipts_today'), fn ($q) => $q->whereIn('movement_type', ['purchase_in', 'purchase_receipt'])->whereDate('movement_date', today()))->when($request->string('movement_type')->toString(), fn ($q, $type) => $q->where('movement_type', $type))->when($request->integer('stock_location_id'), fn ($q, $id) => $q->where('stock_location_id', $id))->when($from, fn ($q) => $q->whereDate('movement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('movement_date', '<=', $to))->when($search, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%"))))->latest()->get();

        $headers = $canViewCost ? [__('application.date_eb9a4bc1'), __('application.product_dd3b86d1'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.quantity_44f6af69'), __('application.cost_64ae43e8'), __('application.created_by_43de2bcd')] : [__('application.date_eb9a4bc1'), __('application.product_dd3b86d1'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.quantity_44f6af69'), __('application.created_by_43de2bcd')];

        return [__('application.stock_movement_report_83f4569f'), $headers, $rows->map(function ($movement) use ($canViewCost): array {
            $row = [$this->formatDate($movement->movement_date), $movement->product?->displayNameWithSize(), $movement->stockLocation?->name, ($movement->movement_type === 'opening_stock' ? __('application.opening_stock_79ec9b63') : $movement->movement_type), $movement->quantity];
            if ($canViewCost) {
                $row[] = $this->formatCurrency($movement->unit_cost);
            }
            $row[] = $movement->creator?->name;

            return $row;
        })->all(), []];
    }

    private function stockTransfers(Request $request, ?int $branchId, ?string $from, ?string $to, string $search): array
    {
        $rows = StockTransfer::with(['fromLocation', 'toLocation', 'createdBy'])->withCount('items')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('transfer_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('transfer_date', '<=', $to))->when($search, fn ($q) => $q->where('transfer_number', 'like', "%{$search}%"))->latest()->get();

        return [__('application.stock_transfer_report_96a7daae'), [__('application.transfer_87c43f1d'), __('application.date_eb9a4bc1'), __('application.from_3f66052a'), __('application.to_ae79ea1e'), __('application.items_44d25b5d'), __('application.status_bae7d5be'), __('application.created_by_43de2bcd')], $rows->map(fn ($t) => [$t->transfer_number, $this->formatDate($t->transfer_date), $t->fromLocation?->name, $t->toLocation?->name, $t->items_count, UiText::status($t->status), $t->createdBy?->name])->all(), []];
    }

    private function purchaseEmailLogs(Request $request, ?string $from, ?string $to, string $search): array
    {
        $rows = PurchaseEmailLog::with(['purchase.supplier', 'sentBy'])->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->when($search, fn ($q) => $q->where('recipient_email', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%"))->latest()->get();

        return [__('application.purchase_email_report_a39de8db'), [__('application.purchase_number_bef19154'), __('application.supplier_55edd462'), __('application.recipient_90343260'), __('application.status_bae7d5be'), __('application.sent_by_a027e031'), __('application.sent_date_5feb6f43'), __('application.error_7f2f6a15')], $rows->map(fn ($log) => [$log->purchase?->reference_number, $log->purchase?->supplier?->name, $log->recipient_email, UiText::status($log->status), $log->sentBy?->name, $log->sent_at?->format('Y-m-d H:i'), $log->error_message])->all(), []];
    }

    private function stockValuation(?int $branchId, ?int $stockLocationId = null, string $search = ''): array
    {
        $rows = collect(app(FinancialReportService::class)->stockValuation($branchId, $stockLocationId, $search));

        return [__('application.stock_valuation_report_dd06256f'), [__('application.branch_1627510b'), __('application.location_d219c681'), __('application.product_dd3b86d1'), __('application.measurement_type_bbc2cb53'), __('application.size_b7152342'), __('application.category_a3c686e7'), __('application.unit_f6b935ab'), __('application.stock_bcecf456'), __('application.average_cost_128a1828'), __('application.value_8dce170d')], $rows->map(fn ($row) => [$row['branch'], $row['location'], $row['product'], $row['measurement_type'], $row['size'] ?: '—', $row['category'], $row['unit'], $row['quantity'], $this->formatCurrency($row['average_cost']), $this->formatCurrency($row['value'])])->all(), [__('application.total_value_4949c4c8') => $this->formatCurrency($rows->sum('value'))]];
    }

    private function profitLoss(?int $branchId, ?string $from, ?string $to): array
    {
        $report = app(FinancialReportService::class)->profitLoss($branchId, $from ?: now()->startOfMonth()->toDateString(), $to ?: today()->toDateString());

        return [__('application.profit_loss_4a12196f'), [__('application.metric_b2bb7604'), __('application.amount_43dc8532')], collect($report)->map(fn ($value, $key) => [UiText::label($key), $this->formatCurrency($value)])->values()->all(), []];
    }

    private function cashbook(?int $branchId, ?string $from, ?string $to): array
    {
        $rows = CashbookSession::with('branch')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('session_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('session_date', '<=', $to))->latest()->get();

        return [__('application.cashbook_report_e9f0cb72'), [__('application.date_eb9a4bc1'), __('application.branch_1627510b'), __('application.opening_56e44065'), __('application.cash_in_cf5d476c'), __('application.cash_out_e6b54fb5'), __('application.expected_6ac87f34'), __('application.actual_7bb2ee60'), __('application.difference_b16f1f66')], $rows->map(fn ($s) => [$this->formatDate($s->session_date), $s->branch?->name, $this->formatCurrency($s->opening_cash), $this->formatCurrency($s->cash_in), $this->formatCurrency($s->cash_out), $this->formatCurrency($s->expected_cash), $this->formatCurrency($s->actual_cash), $this->formatCurrency($s->difference)])->all(), []];
    }
}
