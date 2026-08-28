<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\WhatsAppRecipient;
use Carbon\CarbonInterface;

class WhatsAppMessageFactory
{
    public function __construct(
        private WhatsAppTemplateService $templates,
        private WhatsAppDailySummaryService $dailySummaries,
    ) {}

    public function saleCompleted(Sale $sale): string
    {
        $sale->load(['company', 'branch', 'customer', 'soldBy', 'createdBy', 'items', 'payments']);

        $currency = $sale->company?->currency ?: 'TZS';
        $timezone = $sale->company?->timezone ?: config('app.timezone');
        $customer = $sale->temporary_customer_name
            ?: $sale->customer?->name
            ?: 'Walk-in Customer';
        $payment = $sale->payments->pluck('payment_method')->filter()->unique()
            ->map(fn (string $method): string => str($method)->replace('_', ' ')->title()->toString())
            ->join(', ');
        $itemQuantity = (float) $sale->items->sum('quantity');

        return $this->templates->render($sale->company, 'sale_completed', [
            'sale_number' => $sale->sale_number,
            'date' => $sale->created_at?->clone()->timezone($timezone)->format('d M Y H:i') ?: '-',
            'branch' => $sale->branch?->name ?: '-',
            'cashier' => $sale->soldBy?->name ?: $sale->createdBy?->name ?: '-',
            'customer' => $customer,
            'items' => $itemQuantity === floor($itemQuantity) ? number_format($itemQuantity, 0) : rtrim(rtrim(number_format($itemQuantity, 4, '.', ''), '0'), '.'),
            'currency' => $currency,
            'total' => $this->money($sale->total_amount),
            'paid' => $this->money($sale->paid_amount),
            'balance' => $this->money($sale->balance_amount),
            'payment' => $payment ?: '-',
        ]);
    }

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
        return $this->dailySummaries->message($this->dailySummaries->build($company, $recipient, $date));
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0);
    }
}
