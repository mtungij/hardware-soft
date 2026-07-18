<?php

use App\Models\Sale;
use App\Models\Setting;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null, 'settings' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['customer', 'createdBy', 'items.product', 'items.stockLocation', 'items.sellingUnit', 'items.baseUnit', 'payments']);
    $this->settings = Setting::first();
});

?>

<div>
    <x-page-header title="Receipt" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), 'Receipt' => null]">
        <button onclick="window.print()" class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Print Receipt</button>
    </x-page-header>

    <div class="mx-auto max-w-sm rounded-xl bg-white p-5 font-mono text-sm shadow-soft print:shadow-none dark:bg-white dark:text-slate-950">
        <div class="text-center">
            <p class="text-lg font-black">{{ $settings?->company_name ?? 'Hardex POS' }}</p>
            <p>{{ $settings?->company_phone }}</p>
            <p>{{ $settings?->company_email }}</p>
            <p>{{ $settings?->company_address }}</p>
        </div>

        <div class="my-4 border-y border-dashed border-slate-400 py-3">
            <p>Receipt: {{ $sale->sale_number }}</p>
            <p>Date: {{ $sale->created_at?->format('M d, Y H:i') }}</p>
            <p>Cashier: {{ $sale->createdBy?->name }}</p>
            <p>Customer: {{ $sale->customer?->name ?? 'Walk-in Customer' }}</p>
            <p>Sale Type: {{ $sale->saleTypeLabel() }}</p>
        </div>

        <div class="space-y-2">
            @foreach ($sale->items as $item)
                @php
                    $discountPerUnit = (float) ($item->discount_per_unit ?? 0);
                    $netUnitPrice = (float) ($item->net_unit_price ?? ((float) $item->unit_price - $discountPerUnit));
                    $lineDiscount = (float) ($item->discount_total ?? $item->discount_amount);
                @endphp
                <div>
                    <div class="flex justify-between gap-3">
                        <span>{{ $item->product?->displayName() }}</span>
                        <span>{{ \App\Support\NumberFormatter::money($item->line_total) }}</span>
                    </div>
                    @if ($item->product?->sizeLabel())
                        <p class="text-xs">Size: {{ $item->product->sizeLabel() }}</p>
                    @endif
                    <p class="text-xs">Sale Type: {{ str($item->sale_type ?? 'retail')->title() }}</p>
                    <p class="text-xs">Sehemu ya Stock: {{ $item->sold_from_label ?: ($item->stockLocation ? \App\Support\InventorySettings::stockLocationLabel($item->stockLocation) : '-') }}</p>
                    <p class="text-xs">Selling Quantity: {{ \App\Support\NumberFormatter::quantity($item->quantity) }} {{ $item->sellingUnit?->short_name }} x {{ \App\Support\NumberFormatter::money($item->unit_price) }}</p>
                    <p class="text-xs">Base Quantity Deducted: {{ \App\Support\NumberFormatter::quantity($item->base_quantity ?: $item->quantity) }} {{ $item->baseUnit?->short_name }}</p>
                    @if ($discountPerUnit > 0)
                        <p class="text-xs">Discount: {{ \App\Support\NumberFormatter::money($discountPerUnit) }} each / {{ \App\Support\NumberFormatter::money($lineDiscount) }} total</p>
                        <p class="text-xs">Net Unit Price: {{ \App\Support\NumberFormatter::money($netUnitPrice) }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="my-4 border-t border-dashed border-slate-400 pt-3">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ \App\Support\NumberFormatter::money($sale->subtotal) }}</span></div>
            <div class="flex justify-between"><span>Discount</span><span>{{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</span></div>
            <div class="flex justify-between"><span>Tax/VAT</span><span>{{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</span></div>
            <div class="flex justify-between text-base font-black"><span>Total</span><span>{{ \App\Support\NumberFormatter::money($sale->total_amount) }}</span></div>
            <div class="flex justify-between"><span>Paid</span><span>{{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</span></div>
            <div class="flex justify-between"><span>Balance</span><span>{{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</span></div>
            <div class="flex justify-between"><span>Change</span><span>{{ \App\Support\NumberFormatter::money($sale->change_amount) }}</span></div>
        </div>

        <div class="border-t border-dashed border-slate-400 pt-3 text-center text-xs">
            <p>{{ $settings?->receipt_footer_text ?? 'Thank you for shopping with Hardex.' }}</p>
        </div>
    </div>
</div>
