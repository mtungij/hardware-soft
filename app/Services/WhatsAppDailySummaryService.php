<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\GoodsReceivingNote;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WhatsAppRecipient;
use App\Support\AuthorizationScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WhatsAppDailySummaryService
{
    /**
     * Build only the fields the recipient is authorized to receive. Sensitive
     * queries are deliberately not executed when their permission is absent.
     */
    public function build(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date): array
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereDate('sale_date', $date->toDateString())
            ->where('status', 'completed');
        $this->scopeSales($sales, $recipient);

        $saleIds = (clone $sales)->pluck('id');
        $total = (float) (clone $sales)->sum('total_amount');
        $credit = (float) SalePayment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('sale_id', $saleIds->all() ?: [0])
            ->where('payment_method', 'credit')
            ->sum('amount');

        $data = [
            'company_name' => $company->company_name,
            'scope_label' => $this->scopeLabel($company, $recipient),
            'report_date' => $date->toDateString(),
            'sales' => [
                'total' => $total,
                'cash' => max(0, $total - $credit),
                'credit' => $credit,
                'transactions' => (clone $sales)->count(),
            ],
            'top_products' => DB::table('sale_items')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sale_items.company_id', $company->id)
                ->whereIn('sale_items.sale_id', $saleIds->all() ?: [0])
                ->select('products.name', DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.line_total) as amount'))
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('amount')->limit(5)->get()->map(fn ($row): array => [
                    'name' => $row->name,
                    'quantity' => (float) $row->quantity,
                    'amount' => (float) $row->amount,
                ])->all(),
        ];

        $user = $recipient->user;

        if ($user?->can('sales.view')) {
            $data['sales']['discounts'] = (float) (clone $sales)->sum('discount_amount');
            $data['sales']['cancelled'] = $this->cancelledSales($company, $recipient, $date);
        }

        if ($this->canViewReceivables($user)) {
            $payments = CustomerPayment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereDate('payment_date', $date->toDateString());
            $this->scopeActivity($payments, $recipient, 'received_by');

            $receivables = Sale::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'completed')
                ->where('balance_amount', '>', 0);
            $this->scopeSales($receivables, $recipient);

            $data['receivables'] = [
                'payments_received' => (float) $payments->sum('amount'),
                'amount_due' => (float) (clone $receivables)->whereDate('expected_payment_date', $date->toDateString())->sum('balance_amount'),
                'amount_overdue' => (float) (clone $receivables)->whereDate('expected_payment_date', '<', $date->toDateString())->sum('balance_amount'),
            ];
        }

        if ($user?->can('stock.view')) {
            $data['stock'] = $this->stockCounts($company, $recipient);
        }

        if ($user?->hasAnyPermission(['purchases.view', 'reports.purchases'])) {
            $purchases = Purchase::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereDate('purchase_date', $date->toDateString());
            $this->scopeActivity($purchases, $recipient, 'created_by');
            $grns = GoodsReceivingNote::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereDate('received_date', $date->toDateString());
            $this->scopeActivity($grns, $recipient, 'received_by');
            $outstanding = Purchase::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('status', ['draft', 'ordered', 'partial']);
            $this->scopeActivity($outstanding, $recipient, 'created_by');

            $data['purchases'] = [
                'amount' => (float) $purchases->sum('total_amount'),
                'goods_received' => $grns->count(),
                'outstanding_orders' => $outstanding->count(),
            ];
        }

        if ($this->canViewProfit($user)) {
            $cogs = (float) DB::table('sale_items')
                ->where('company_id', $company->id)
                ->whereIn('sale_id', $saleIds->all() ?: [0])
                ->sum(DB::raw('CASE WHEN base_unit_cost IS NOT NULL THEN base_quantity * base_unit_cost ELSE quantity * COALESCE(unit_cost, 0) END'));
            $expenses = Expense::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereDate('expense_date', $date->toDateString());
            $this->scopeActivity($expenses, $recipient, 'paid_by');
            $expenseTotal = (float) $expenses->sum('amount');
            $grossProfit = $total - $cogs;

            $data['financial'] = [
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'expenses' => $expenseTotal,
                'net_profit' => $grossProfit - $expenseTotal,
                'profit_margin' => $total > 0 ? ($grossProfit / $total) * 100 : 0,
            ];
        }

        if ($user?->can('stock.view_value')) {
            $stock = DB::table('stock_movements')->where('stock_movements.company_id', $company->id);
            $this->scopeStock($stock, $recipient);
            $rows = $stock->select(
                'stock_movements.product_id',
                'stock_movements.stock_location_id',
                DB::raw('SUM(stock_movements.quantity_in - stock_movements.quantity_out) as quantity'),
                DB::raw("SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) * stock_movements.unit_cost ELSE 0 END) as cost_numerator"),
                DB::raw("SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) ELSE 0 END) as cost_denominator"),
            )->groupBy('stock_movements.product_id', 'stock_movements.stock_location_id')->get();
            $data['stock_valuation'] = (float) $rows->sum(fn ($row): float => (float) $row->quantity * ((float) $row->cost_denominator > 0 ? (float) $row->cost_numerator / (float) $row->cost_denominator : 0));
        }

        return $data;
    }

    public function message(array $data): string
    {
        $sales = $data['sales'];
        $lines = [
            'HARDEX DAILY SUMMARY',
            $data['scope_label'],
            CarbonImmutable::parse($data['report_date'])->format('d M Y'), '',
            'Sales: TZS '.$this->money($sales['total']),
            'Transactions: '.$sales['transactions'],
            'Cash Sales: TZS '.$this->money($sales['cash']),
            'Credit Sales: TZS '.$this->money($sales['credit']),
        ];

        if (isset($data['receivables'])) {
            $lines[] = 'Payments Received: TZS '.$this->money($data['receivables']['payments_received']);
        }

        $top = $data['top_products'][0] ?? null;
        $lines[] = 'Top Product: '.($top ? $top['name'].' — TZS '.$this->money($top['amount']) : '-');

        if (isset($data['stock'])) {
            $lines[] = 'Low Stock: '.$data['stock']['low'];
            $lines[] = 'Out of Stock: '.$data['stock']['out'];
        }

        if (isset($data['financial'])) {
            $financial = $data['financial'];
            array_push($lines, '',
                'COGS: TZS '.$this->money($financial['cogs']),
                'Gross Profit: TZS '.$this->money($financial['gross_profit']),
                'Expenses: TZS '.$this->money($financial['expenses']),
                'Net Profit: TZS '.$this->money($financial['net_profit']),
                'Profit Margin: '.number_format($financial['profit_margin'], 1).'%',
            );
        }

        if (array_key_exists('stock_valuation', $data)) {
            $lines[] = 'Stock Value: TZS '.$this->money($data['stock_valuation']);
        }

        return implode("\n", $lines);
    }

    private function scopeSales(Builder $query, WhatsAppRecipient $recipient): void
    {
        if ($recipient->user) {
            AuthorizationScope::sales($query, $recipient->user);
            AuthorizationScope::reports($query, $recipient->user);
        } elseif ($recipient->scope === 'branch') {
            $query->where('branch_id', $recipient->branch_id);
        }
    }

    private function scopeActivity($query, WhatsAppRecipient $recipient, string $ownerColumn): void
    {
        if ($recipient->user) {
            $scope = AuthorizationScope::scopeFor($recipient->user, 'report_scope', AuthorizationScope::BRANCH);
            if ($scope === AuthorizationScope::OWN) {
                $query->where($ownerColumn, $recipient->user->id);
            } elseif ($scope !== AuthorizationScope::COMPANY) {
                $query->where('branch_id', $recipient->user->branch_id);
            }
        } elseif ($recipient->scope === 'branch') {
            $query->where('branch_id', $recipient->branch_id);
        }
    }

    private function scopeStock($query, WhatsAppRecipient $recipient): void
    {
        if ($recipient->user) {
            $query->whereIn('stock_movements.stock_location_id', AuthorizationScope::stockLocationIds($recipient->user)->all() ?: [0]);
        } elseif ($recipient->scope === 'branch') {
            $query->join('stock_locations', 'stock_locations.id', '=', 'stock_movements.stock_location_id')
                ->where('stock_locations.branch_id', $recipient->branch_id);
        }
    }

    private function stockCounts(Company $company, WhatsAppRecipient $recipient): array
    {
        $query = DB::table('products')
            ->join('stock_locations', 'stock_locations.company_id', '=', 'products.company_id')
            ->leftJoin('stock_movements', function ($join): void {
                $join->on('stock_movements.product_id', '=', 'products.id')
                    ->on('stock_movements.stock_location_id', '=', 'stock_locations.id');
            })
            ->where('products.company_id', $company->id)
            ->where('products.status', 'active')
            ->where('stock_locations.is_active', true)
            ->where('stock_locations.status', 'active')
            ->when($recipient->user, fn ($query) => $query->whereIn('stock_locations.id', AuthorizationScope::stockLocationIds($recipient->user)->all() ?: [0]))
            ->when(! $recipient->user && $recipient->scope === 'branch', fn ($query) => $query->where('stock_locations.branch_id', $recipient->branch_id))
            ->select('products.id', 'stock_locations.id as location_id', 'products.reorder_level', DB::raw('COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) as quantity'))
            ->groupBy('products.id', 'stock_locations.id', 'products.reorder_level')
            ->get();

        return [
            'low' => $query->filter(fn ($row): bool => (float) $row->quantity > 0 && (float) $row->quantity <= (float) $row->reorder_level)->count(),
            'out' => $query->filter(fn ($row): bool => (float) $row->quantity <= 0)->count(),
        ];
    }

    private function cancelledSales(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date): int
    {
        $query = Sale::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereDate('cancelled_at', $date->toDateString())->where('status', 'cancelled');
        $this->scopeSales($query, $recipient);

        return $query->count();
    }

    private function scopeLabel(Company $company, WhatsAppRecipient $recipient): string
    {
        if (! $recipient->user) {
            return $recipient->scope === 'branch' ? ($recipient->branch?->name ?: 'Branch') : $company->company_name;
        }

        $salesScope = AuthorizationScope::scopeFor($recipient->user, 'sales_scope', AuthorizationScope::BRANCH);
        $reportScope = AuthorizationScope::scopeFor($recipient->user, 'report_scope', AuthorizationScope::BRANCH);
        if ($salesScope === AuthorizationScope::OWN || $reportScope === AuthorizationScope::OWN) {
            return 'Own activity — '.$recipient->user->name;
        }
        if ($salesScope !== AuthorizationScope::COMPANY || $reportScope !== AuthorizationScope::COMPANY) {
            return $recipient->branch?->name ?: 'Branch';
        }

        return $company->company_name;
    }

    private function canViewReceivables(?User $user): bool
    {
        return $user?->hasAnyPermission(['dashboard.receivables', 'reports.receivables', 'accounting.view', 'customer-balances.view']) ?? false;
    }

    private function canViewProfit(?User $user): bool
    {
        return $user?->hasAnyPermission(['dashboard.profit', 'sales.view_cost', 'sales.view_profit', 'reports.profit']) ?? false;
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0);
    }
}
