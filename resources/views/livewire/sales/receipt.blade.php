<?php

use App\Models\Sale;
use App\Models\Setting;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.print');

state(['sale' => null, 'settings' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load([
        'customer',
        'company',
        'createdBy',
        'soldBy',
        'items.product.sellingUnit',
        'items.product.unit',
        'items.sellingUnit',
        'payments',
    ]);
    $this->settings = Setting::withoutGlobalScopes()
        ->where('company_id', $sale->company_id)
        ->first();
});

?>

<div>
    @php
        $paperSize = request()->query('paper') === '58' ? '58' : '80';
        $isOutstanding = (float) $sale->balance_amount > 0 || $sale->payment_status !== 'paid';
        $customerName = $sale->temporary_customer_name ?: $sale->customer?->name ?: 'Walk-in Customer';
        $company = $sale->company;
        $companyName = $company?->company_name ?: $settings?->company_name ?: config('app.name');
        $companyTagline = $company?->tagline ?: $settings?->company_tagline;
        $companyPhone = $company?->phone ?: $settings?->company_phone;
        $alternatePhone = $company?->alternate_phone ?: $settings?->alternate_phone ?: $company?->whatsapp_number ?: $settings?->whatsapp_number;
        $alternatePhone = $alternatePhone !== $companyPhone ? $alternatePhone : null;
        $companyEmail = $company?->email ?: $settings?->company_email;
        $companyAddress = $company?->address ?: $settings?->company_address;
        $companyWebsite = $company?->website ?: $settings?->company_website;
        $showTaxIdentifiers = (bool) ($company?->show_tax_identifiers_on_receipt ?? $settings?->show_tax_identifiers_on_receipt);
        $tinNumber = $company?->tin_number ?: $settings?->tin_number;
        $vatNumber = $company?->vrn_number ?: $settings?->vrn_number;
        $logoPath = $company?->logo ?: $settings?->company_logo;
        $logoUrl = $logoPath
            ? (\Illuminate\Support\Str::startsWith($logoPath, ['http://', 'https://', '//', '/'])
                ? $logoPath
                : (\Illuminate\Support\Str::startsWith($logoPath, ['storage/', 'images/', 'assets/'])
                    ? asset(ltrim($logoPath, '/'))
                    : asset('storage/'.ltrim($logoPath, '/'))))
            : null;
        $receiptTitle = 'SALES RECEIPT';
        $footerMessage = $settings?->receipt_footer_message;
    @endphp

    <style>
        .customer-receipt {
            --receipt-width: 72mm;
            --receipt-padding: 3mm;
            box-sizing: border-box;
            position: static;
            width: var(--receipt-width);
            height: auto;
            min-height: 0;
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
        .receipt-body,
        .receipt-summary,
        .receipt-header,
        .receipt-footer {
            position: static;
            float: none;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .receipt-footer {
            margin-top: 10px;
            text-align: center;
        }

        @media print {
            @page {
                margin: 0;
            }

            html,
            body {
                width: auto;
                height: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                background: #fff;
            }

            #customer-receipt {
                position: static;
                float: none;
                display: block;
                height: auto;
                min-height: 0;
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
                <button type="button" onclick="printCustomerReceipt()" class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Print Receipt</button>
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
                <img src="{{ $logoUrl }}" alt="{{ $companyName }} logo" class="receipt-logo">
            @endif
            @if ($companyName)
                <p class="text-[1.45em] font-black uppercase leading-tight">{{ $companyName }}</p>
            @endif
            @if ($companyTagline)
                <p class="mt-0.5 font-semibold">{{ $companyTagline }}</p>
            @endif
            @if ($companyPhone)
                <p class="mt-1">{{ $companyPhone }}</p>
            @endif
            @if ($alternatePhone)
                <p>{{ $alternatePhone }}</p>
            @endif
            @if ($companyEmail)
                <p class="break-all">{{ $companyEmail }}</p>
            @endif
            @if ($companyAddress)
                <p>{{ $companyAddress }}</p>
            @endif
            @if ($companyWebsite)
                <p class="break-all">{{ $companyWebsite }}</p>
            @endif
            @if ($showTaxIdentifiers && $tinNumber)
                <p>TIN: {{ $tinNumber }}</p>
            @endif
            @if ($showTaxIdentifiers && $vatNumber)
                <p>VAT: {{ $vatNumber }}</p>
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

        <div class="receipt-body">
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

        @if (filled($footerMessage))
            <div class="receipt-footer whitespace-pre-line border-t border-dashed border-slate-500 pt-[2mm] text-center text-[0.9em]">{{ $footerMessage }}</div>
        @endif
    </div>

    <script>
        window.printCustomerReceipt = async function () {
            const receipt = document.getElementById('customer-receipt');
            const images = receipt ? Array.from(receipt.querySelectorAll('img')) : [];

            await Promise.all(images.map((image) => new Promise((resolve) => {
                const finish = () => {
                    if (! image.naturalWidth) {
                        image.remove();
                    }

                    resolve();
                };

                if (image.complete) {
                    finish();
                    return;
                }

                image.addEventListener('load', finish, { once: true });
                image.addEventListener('error', finish, { once: true });
                window.setTimeout(finish, 5000);
            })));

            window.print();
        };
    </script>
</div>
