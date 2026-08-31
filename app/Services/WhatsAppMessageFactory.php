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
        private WhatsAppLocalization $localization,
    ) {}

    public function saleCompleted(Sale $sale): string
    {
        $sale->load(['company', 'branch', 'customer', 'soldBy', 'createdBy', 'items.product', 'items.productSize', 'items.sellingUnit', 'payments']);

        $currency = $sale->company?->currency ?: 'TZS';
        $timezone = $sale->company?->timezone ?: config('app.timezone');
        $customer = $sale->temporary_customer_name
            ?: $sale->customer?->name
            ?: $this->localization->get($sale->company, 'common.walk_in_customer');
        $payment = $sale->payments->pluck('payment_method')->filter()->unique()
            ->map(fn (string $method): string => $this->paymentMethod($sale->company, $method))
            ->join(', ');
        $itemQuantity = (float) $sale->items->sum('quantity');
        $displayedItems = $sale->items->take(10)->map(function ($item): string {
            $name = $item->productDisplayNameWithSize() ?: '-';
            $unit = $item->selling_unit_code_snapshot
                ?: $item->selling_unit_name_snapshot
                ?: $item->sellingUnit?->short_name
                ?: $item->sellingUnit?->name
                ?: '-';

            return '• '.$name.' — '.$this->quantity($item->quantity).' '.$unit;
        });
        $remainingItems = max(0, $sale->items->count() - $displayedItems->count());
        if ($remainingItems > 0) {
            $displayedItems->push($this->localization->get($sale->company, 'sale_completed.more_items', ['count' => $remainingItems]));
        }
        $products = $displayedItems->isEmpty() ? '-' : $displayedItems->implode("\n");
        $totalQuantity = $this->quantity($itemQuantity);

        return $this->templates->render($sale->company, 'sale_completed', [
            'sale_number' => $sale->sale_number,
            'date' => $this->localization->date($sale->company, $sale->created_at?->clone()->timezone($timezone), true),
            'branch' => $sale->branch?->name ?: '-',
            'cashier' => $sale->soldBy?->name ?: $sale->createdBy?->name ?: '-',
            'customer' => $customer,
            'products' => $products,
            'total_quantity' => $totalQuantity,
            'items' => $products."\n\n".$this->localization->get($sale->company, 'sale_completed.total_quantity').': '.$totalQuantity,
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
            'time' => $this->localization->date($sale->company, $sale->cancelled_at, true),
        ]);
    }

    public function customerPayment(CustomerPayment $payment): string
    {
        $payment->loadMissing(['customer', 'branch', 'receivedBy']);

        return $this->templates->render($payment->company, 'customer_payment_received', [
            'customer' => $payment->customer?->name ?: $this->localization->get($payment->company, 'common.customer'), 'amount' => $this->money($payment->amount),
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

    private function quantity(mixed $quantity): string
    {
        $quantity = (float) $quantity;

        return $quantity === floor($quantity)
            ? number_format($quantity, 0)
            : rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }

    private function paymentMethod(Company $company, string $method): string
    {
        $key = 'whatsapp.common.payment_methods.'.$method;
        $translated = __($key, [], $this->localization->language($company));

        return $translated === $key ? str($method)->replace('_', ' ')->title()->toString() : $translated;
    }
}
