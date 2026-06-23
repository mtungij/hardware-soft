<?php

use App\Models\Sale;
use App\Models\Setting;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null, 'settings' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['customer', 'createdBy', 'items.product', 'payments']);
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
        </div>

        <div class="space-y-2">
            @foreach ($sale->items as $item)
                <div>
                    <div class="flex justify-between gap-3">
                        <span>{{ $item->product?->name }}</span>
                        <span>{{ number_format((float) $item->line_total, 2) }}</span>
                    </div>
                    <p class="text-xs">{{ number_format((float) $item->quantity, 2) }} x {{ number_format((float) $item->unit_price, 2) }}</p>
                </div>
            @endforeach
        </div>

        <div class="my-4 border-t border-dashed border-slate-400 pt-3">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format((float) $sale->subtotal, 2) }}</span></div>
            <div class="flex justify-between"><span>Discount</span><span>{{ number_format((float) $sale->discount_amount, 2) }}</span></div>
            <div class="flex justify-between"><span>Tax/VAT</span><span>{{ number_format((float) $sale->tax_amount, 2) }}</span></div>
            <div class="flex justify-between text-base font-black"><span>Total</span><span>{{ number_format((float) $sale->total_amount, 2) }}</span></div>
            <div class="flex justify-between"><span>Paid</span><span>{{ number_format((float) $sale->paid_amount, 2) }}</span></div>
            <div class="flex justify-between"><span>Balance</span><span>{{ number_format((float) $sale->balance_amount, 2) }}</span></div>
            <div class="flex justify-between"><span>Change</span><span>{{ number_format((float) $sale->change_amount, 2) }}</span></div>
        </div>

        <div class="border-t border-dashed border-slate-400 pt-3 text-center text-xs">
            <p>{{ $settings?->receipt_footer_text ?? 'Thank you for shopping with Hardex.' }}</p>
        </div>
    </div>
</div>
