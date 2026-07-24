<?php

use App\Models\Sale;
use App\Models\Setting;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null, 'settings' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load([
        'customer',
        'createdBy',
        'soldBy',
        'items.product.sellingUnit',
        'items.product.unit',
        'items.sellingUnit',
        'payments',
    ]);
    $this->settings = Setting::first();
});

?>

<div>
    @php
        $paperSize = request()->query('paper') === '58' ? '58' : '80';
        $isOutstanding = (float) $sale->balance_amount > 0 || $sale->payment_status !== 'paid';
        $customerName = $sale->temporary_customer_name ?: $sale->customer?->name ?: 'Walk-in Customer';
        $alternatePhone = $settings?->alternate_phone ?: $settings?->whatsapp_number;
        $alternatePhone = $alternatePhone !== $settings?->company_phone ? $alternatePhone : null;
        $logoPath = $settings?->company_logo;
        $logoUrl = $logoPath
            ? (\Illuminate\Support\Str::startsWith($logoPath, ['http://', 'https://', '/'])
                ? $logoPath
                : asset('storage/'.ltrim($logoPath, '/')))
            : null;
        $receiptTitle = 'SALES RECEIPT';
    @endphp

    <style>
        .customer-receipt {
            --receipt-width: 72mm;
            --receipt-padding: 3mm;
            box-sizing: border-box;
            width: var(--receipt-width);
            padding: var(--receipt-padding);
            font-size: 11px;
            line-height: 1.35;
        }

        .customer-receipt[data-paper-size="58"] {
            --receipt-width: 52mm;
            --receipt-padding: 2mm;
            font-size: 10px;
        }

        .receipt-logo {
            display: block;
            width: auto;
            max-width: 180px;
            height: auto;
            max-height: 80px;
            margin: 0 auto 2mm;
            object-fit: contain;
        }

        .customer-receipt[data-paper-size="58"] .receipt-logo {
            max-width: 120px;
            max-height: 60px;
        }

        .receipt-item,
        .receipt-summary,
        .receipt-header,
        .receipt-footer {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        @media print {
            @page {
                margin: 0;
            }

            body * {
                visibility: hidden !important;
            }

            #customer-receipt,
            #customer-receipt * {
                visibility: visible !important;
            }

            #customer-receipt {
                position: absolute;
                inset: 0 auto auto 0;
                margin: 0;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .receipt-no-print {
                display: none !important;
            }
        }
    </style>

    <div class="receipt-no-print">
        <x-page-header title="Receipt" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), 'Receipt' => null]">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => 58]) }}" class="{{ $paperSize === '58' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 dark:border-slate-700' }} rounded-lg px-3 py-2 text-sm font-bold">58mm</a>
                <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => 80]) }}" class="{{ $paperSize === '80' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 dark:border-slate-700' }} rounded-lg px-3 py-2 text-sm font-bold">80mm</a>
                <button onclick="window.print()" class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Print Receipt</button>
            </div>
        </x-page-header>
    </div>

    <div
        id="customer-receipt"
        data-paper-size="{{ $paperSize }}"
        class="customer-receipt mx-auto bg-white font-mono text-slate-950 shadow-soft"
    >
        <div class="receipt-header text-center">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $settings?->company_name }} logo" class="receipt-logo">
            @endif
            @if ($settings?->company_name)
                <p class="text-[1.45em] font-black uppercase leading-tight">{{ $settings->company_name }}</p>
            @endif
            @if ($settings?->company_tagline)
                <p class="mt-0.5 font-semibold">{{ $settings->company_tagline }}</p>
            @endif
            @if ($settings?->company_phone)
                <p class="mt-1">{{ $settings->company_phone }}</p>
            @endif
            @if ($alternatePhone)
                <p>{{ $alternatePhone }}</p>
            @endif
            @if ($settings?->company_email)
                <p class="break-all">{{ $settings->company_email }}</p>
            @endif
            @if ($settings?->company_address)
                <p>{{ $settings->company_address }}</p>
            @endif
            @if ($settings?->company_website)
                <p class="break-all">{{ $settings->company_website }}</p>
            @endif
            @if ($settings?->show_tax_identifiers_on_receipt && $settings?->tin_number)
                <p>TIN: {{ $settings->tin_number }}</p>
            @endif
            @if ($settings?->show_tax_identifiers_on_receipt && $settings?->vrn_number)
                <p>VAT: {{ $settings->vrn_number }}</p>
            @endif
            <p class="mt-[2mm] border-y border-dashed border-slate-500 py-1 text-[1.15em] font-black tracking-wide">{{ $receiptTitle }}</p>
        </div>

        <div class="receipt-header my-[2mm] border-b border-dashed border-slate-500 pb-[2mm] text-left">
            <p><span class="font-bold">Receipt:</span> {{ $sale->sale_number }}</p>
            <p><span class="font-bold">Date:</span> {{ $sale->created_at?->format('d M Y H:i') }}</p>
            <p><span class="font-bold">Cashier:</span> {{ $sale->soldBy?->name ?: $sale->createdBy?->name }}</p>
            <p><span class="font-bold">Customer:</span> {{ $customerName }}</p>
            <p><span class="font-bold">Sale Type:</span> {{ $sale->saleTypeLabel() }}</p>
        </div>

        <div>
            @foreach ($sale->items as $item)
                @php
                    $discountPerUnit = (float) ($item->discount_per_unit ?? 0);
                    $sellingUnit = $item->sellingUnit?->short_name
                        ?: $item->product?->sellingUnit?->short_name
                        ?: $item->product?->unit?->short_name;
                @endphp
                <div class="receipt-item border-b border-dashed border-slate-400 py-[2mm]">
                    <p class="font-bold">
                        {{ $item->product?->displayName() }}
                        @if ($item->sizeLabel())
                            {{ $item->sizeLabel() }}
                        @endif
                    </p>
                    <div class="mt-0.5 flex items-baseline justify-between gap-2">
                        <span>
                            {{ \App\Support\NumberFormatter::quantity($item->quantity) }}
                            {{ $sellingUnit }}
                            × {{ \App\Support\NumberFormatter::money($item->unit_price) }}
                        </span>
                        <span class="shrink-0 font-bold">{{ \App\Support\NumberFormatter::money($item->line_total) }}</span>
                    </div>
                    @if ($discountPerUnit > 0)
                        <p class="mt-0.5 text-[0.9em]">Discount: {{ \App\Support\NumberFormatter::money($discountPerUnit) }} each</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="receipt-summary py-[2mm]">
            <div class="flex justify-between gap-2"><span>Subtotal</span><span>{{ \App\Support\NumberFormatter::money($sale->subtotal) }}</span></div>
            @if ((float) $sale->discount_amount > 0)
                <div class="flex justify-between gap-2"><span>Discount</span><span>-{{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</span></div>
            @endif
            @if ((float) $sale->tax_amount > 0)
                <div class="flex justify-between gap-2"><span>Tax/VAT</span><span>{{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</span></div>
            @endif
            <div class="my-1 flex justify-between gap-2 border-y border-slate-500 py-1 text-[1.1em] font-black"><span>TOTAL</span><span>{{ \App\Support\NumberFormatter::money($sale->total_amount) }}</span></div>
            <div class="flex justify-between gap-2"><span>Paid</span><span>{{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</span></div>
            @if ($isOutstanding)
                <div class="flex justify-between gap-2 font-bold"><span>Outstanding</span><span>{{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</span></div>
            @else
                <div class="flex justify-between gap-2"><span>Change</span><span>{{ \App\Support\NumberFormatter::money($sale->change_amount) }}</span></div>
            @endif
        </div>

        <div class="receipt-footer border-t border-dashed border-slate-500 pt-[2mm] text-center text-[0.9em]">
            <p>{{ $settings?->receipt_footer_text ?? 'Thank you for shopping with us.' }}</p>
        </div>
    </div>
</div>
