<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppRecipient;
use App\Support\AuthorizationScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class WhatsAppMessageFactory
{
    public function __construct(private WhatsAppTemplateService $templates) {}

    public function saleCancelled(Sale $sale): string
    {
        $sale->loadMissing(['branch', 'cancelledBy']);

        return $this->templates->render($sale->company, 'sale_cancelled', [
            'sale_number' => $sale->sale_number, 'branch' => $sale->branch?->name ?: '-',
            'amount' => $this->money($sale->total_amount), 'actor' => $sale->cancelledBy?->name ?: '-',
            'time' => optional($sale->cancelled_at)->format('d M Y H:i'),
        ]);
    }

    public function customerPayment(CustomerPayment $payment): string
    {
        $payment->loadMissing(['customer', 'branch', 'receivedBy']);

        return $this->templates->render($payment->company, 'customer_payment_received', [
            'customer' => $payment->customer?->name ?: 'Customer', 'amount' => $this->money($payment->amount),
            'reference' => $payment->receipt_number ?: $payment->reference_number ?: '-',
            'branch' => $payment->branch?->name ?: '-', 'actor' => $payment->receivedBy?->name ?: '-',
        ]);
    }

    public function productionCompleted(ProductionOrder $order): string
    {
        $order->loadMissing(['product', 'branch']);

        return $this->templates->render($order->company, 'production_completed', [
            'order_number' => $order->order_number, 'product' => $order->product?->name ?: '-',
            'accepted' => $order->accepted_quantity, 'rejected' => $order->rejected_quantity,
            'branch' => $order->branch?->name ?: '-',
        ]);
    }

    public function dailySummary(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date): string
    {
        $sales = Sale::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereDate('sale_date', $date)->where('status', 'completed');
        $this->scopeSalesForRecipient($sales, $recipient);

        $saleIds = (clone $sales)->pluck('id');
        $total = (float) (clone $sales)->sum('total_amount');
        $credit = (float) (clone $sales)->sum('balance_amount');
        $cash = max(0, $total - $credit);
        $transactions = (clone $sales)->count();
        $payments = (float) CustomerPayment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereDate('payment_date', $date)
            ->when($recipient->scope === 'branch', fn ($query) => $query->where('branch_id', $recipient->branch_id))
            ->sum('amount');
        $top = DB::table('sale_items')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereIn('sale_items.sale_id', $saleIds ?: [0])
            ->select('products.name', DB::raw('SUM(sale_items.line_total) as total'))
            ->groupBy('products.id', 'products.name')->orderByDesc('total')->first();

        $lines = [
            'HARDEX DAILY SUMMARY',
            $recipient->scope === 'branch' ? ($recipient->branch?->name ?: 'Branch') : $company->company_name,
            $date->format('d M Y'), '',
            'Sales: TZS '.$this->money($total),
            'Transactions: '.$transactions,
            'Cash Sales: TZS '.$this->money($cash),
            'Credit Sales: TZS '.$this->money($credit),
            'Payments Received: TZS '.$this->money($payments),
            'Top Product: '.($top ? $top->name.' — TZS '.$this->money($top->total) : '-'),
        ];

        if ($this->canSeeFinancials($recipient->user)) {
            $cogs = (float) DB::table('sale_items')->whereIn('sale_id', $saleIds ?: [0])
                ->sum(DB::raw('quantity * COALESCE(unit_cost, 0)'));
            $profit = $total - $cogs;
            array_push($lines, '', 'COGS: TZS '.$this->money($cogs), 'Gross Profit: TZS '.$this->money($profit), 'Profit Margin: '.($total > 0 ? number_format($profit / $total * 100, 1).'%' : '0.0%'));
        }

        return implode("\n", $lines);
    }

    public function canSeeFinancials(?User $user): bool
    {
        return $user !== null && $user->hasAnyPermission(['dashboard.profit', 'sales.view_cost', 'sales.view_profit']);
    }

    private function scopeSalesForRecipient($query, WhatsAppRecipient $recipient): void
    {
        if ($recipient->user) {
            AuthorizationScope::sales($query, $recipient->user);

            return;
        }

        if ($recipient->scope === 'branch') {
            $query->where('branch_id', $recipient->branch_id);
        }
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0);
    }
}
