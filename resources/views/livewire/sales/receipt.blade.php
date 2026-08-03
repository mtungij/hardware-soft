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
        'items.stockLocation',
        'payments',
    ]);
    $this->settings = Setting::withoutGlobalScopes()
        ->where('company_id', $sale->company_id)
        ->first();
});

?>

<div class="receipt-page">
    @php
        $paperSize = request()->query('paper') === '80' ? '80' : '58';
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
        $requestOrigin = request()->getSchemeAndHttpHost();
        $logoUrl = $logoPath
            ? (\Illuminate\Support\Str::startsWith($logoPath, ['http://', 'https://'])
                ? $logoPath
                : (\Illuminate\Support\Str::startsWith($logoPath, '//')
                    ? request()->getScheme().':'.$logoPath
                    : (\Illuminate\Support\Str::startsWith($logoPath, '/')
                        ? $requestOrigin.$logoPath
                        : (\Illuminate\Support\Str::startsWith($logoPath, ['storage/', 'images/', 'assets/'])
                            ? $requestOrigin.'/'.ltrim($logoPath, '/')
                            : $requestOrigin.'/storage/'.ltrim($logoPath, '/')))))
            : null;
        $receiptTitle = 'SALES RECEIPT';
        $footerMessage = $settings?->receipt_footer_message;
    @endphp

    <style>
        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden;
        }

        .receipt-page {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 360px);
            grid-template-areas: "preview actions";
            align-items: start;
            gap: 24px;
            width: 100%;
            min-height: 100dvh;
            box-sizing: border-box;
            padding: 24px;
            background: #f1f5f9;
        }

        .receipt-no-print {
            grid-area: actions;
            min-width: 0;
        }

        .receipt-actions {
            position: sticky;
            top: 24px;
            min-width: 0;
        }

        .receipt-action-card {
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.1);
        }

        .receipt-paper {
            --receipt-width: 72mm;
            --receipt-preview-width: 320px;
            grid-area: preview;
            justify-self: center;
            box-sizing: border-box;
            position: static;
            width: min(100%, var(--receipt-preview-width));
            max-width: var(--receipt-preview-width);
            height: auto;
            min-height: 0;
            margin: 0;
            padding: 16px 12px;
            color: #000;
            background: #fff;
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            font-weight: 500;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .receipt-paper[data-paper-size="58"] {
            --receipt-width: 48mm;
            --receipt-preview-width: 240px;
            font-size: 10.5px;
        }

        .receipt-logo {
            display: block !important;
            width: auto;
            max-width: 28mm;
            height: auto;
            max-height: 16mm;
            margin: 0 auto 1.5mm;
            object-fit: contain;
        }

        .company-name {
            font-size: 15px;
            font-weight: 700;
        }

        .receipt-title {
            font-size: 12.5px;
            font-weight: 700;
        }

        .product-name,
        .receipt-header,
        .receipt-summary {
            font-weight: 600;
        }

        .item-values,
        .summary-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 4px;
        }

        .item-values span:last-child,
        .summary-row span:last-child {
            flex-shrink: 0;
            text-align: right;
            white-space: nowrap;
        }

        .summary-total {
            font-size: 12px;
            font-weight: 700;
        }

        .receipt-paper .receipt-header,
        .receipt-paper .receipt-item,
        .receipt-paper .receipt-title,
        .receipt-paper .summary-total,
        .receipt-paper .receipt-footer {
            border-color: #000 !important;
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

        @media (max-width: 767px) {
            .receipt-page {
                grid-template-columns: minmax(0, 1fr);
                grid-template-areas:
                    "actions"
                    "preview";
                gap: 16px;
                padding: 12px;
            }

            .receipt-actions {
                position: static;
            }
        }

        @media print {
            @page {
                size: {{ $paperSize }}mm auto;
                margin: 0;
            }

            html,
            body {
                width: auto;
                height: auto;
                min-height: 0;
                margin: 0 !important;
                padding: 0 !important;
                color: #000 !important;
                background: #fff !important;
            }

            .receipt-page {
                display: block;
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                background: #fff;
            }

            #customer-receipt {
                position: static;
                float: none;
                display: block;
                width: var(--receipt-width);
                max-width: var(--receipt-width);
                height: auto;
                min-height: 0;
                margin: 0 auto;
                padding: 1.5mm 1mm;
                color: #000 !important;
                background: #fff !important;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            #customer-receipt *,
            #customer-receipt img {
                color: #000 !important;
                visibility: visible !important;
            }

            .no-print,
            .receipt-no-print {
                display: none !important;
            }
        }
    </style>

    <div
        id="customer-receipt"
        data-paper-size="{{ $paperSize }}"
        class="receipt-paper customer-receipt shadow-soft"
    >
        <div class="receipt-header text-center">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $companyName }} logo" class="receipt-logo">
            @endif
            @if ($companyName)
                <p class="company-name uppercase leading-tight">{{ $companyName }}</p>
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
            <p class="receipt-title mt-[2mm] border-y border-dashed py-1 tracking-wide">{{ $receiptTitle }}</p>
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
                    <p class="product-name">
                        {{ $item->product?->displayName() }}
                        @if ($item->sizeLabel())
                            {{ $item->sizeLabel() }}
                        @endif
                    </p>
                    <div class="item-values mt-0.5">
                        <span>
                            {{ \App\Support\NumberFormatter::quantity($item->quantity) }}
                            {{ $sellingUnit }}
                            × {{ \App\Support\NumberFormatter::money($item->unit_price) }}
                        </span>
                        <span class="shrink-0 font-bold">{{ \App\Support\NumberFormatter::money($item->line_total) }}</span>
                    </div>
                    @if($item->sold_from_label || $item->stockLocation)
                        <p class="mt-0.5 text-[0.9em]">From: {{ $item->sold_from_label ?: \App\Support\InventorySettings::stockLocationLabel($item->stockLocation) }}</p>
                    @endif
                    @if ($discountPerUnit > 0)
                        <p class="mt-0.5 text-[0.9em]">Discount: {{ \App\Support\NumberFormatter::money($discountPerUnit) }} each</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="receipt-summary py-[2mm]">
            <div class="summary-row"><span>Subtotal</span><span>{{ \App\Support\NumberFormatter::money($sale->subtotal) }}</span></div>
            @if ((float) $sale->discount_amount > 0)
                <div class="summary-row"><span>Discount</span><span>-{{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</span></div>
            @endif
            @if ((float) $sale->tax_amount > 0)
                <div class="summary-row"><span>Tax/VAT</span><span>{{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</span></div>
            @endif
            <div class="summary-row summary-total my-1 border-y py-1"><span>TOTAL</span><span>{{ \App\Support\NumberFormatter::money($sale->total_amount) }}</span></div>
            <div class="summary-row"><span>Paid</span><span>{{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</span></div>
            @if ($isOutstanding)
                <div class="summary-row font-bold"><span>Outstanding</span><span>{{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</span></div>
            @else
                <div class="summary-row"><span>Change</span><span>{{ \App\Support\NumberFormatter::money($sale->change_amount) }}</span></div>
            @endif
        </div>

        @if (filled($footerMessage))
            <div class="receipt-footer whitespace-pre-line border-t border-dashed border-slate-500 pt-[2mm] text-center text-[0.9em]">{{ $footerMessage }}</div>
        @endif
    </div>

    <aside class="no-print receipt-no-print" data-receipt-actions>
        <div class="receipt-actions">
            <div class="receipt-action-card">
                <div class="border-b border-slate-200 p-5">
                    <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700">Sale completed</span>
                    <h1 class="mt-3 text-2xl font-black text-slate-950">Receipt Preview</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ $sale->sale_number }}</p>
                </div>

                <div class="space-y-5 p-5">
                    <fieldset>
                        <legend class="text-xs font-black uppercase tracking-wide text-slate-500">Receipt width</legend>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => 58]) }}" data-paper-selector="58" class="{{ $paperSize === '58' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700' }} rounded-xl border px-3 py-3 text-center text-sm font-black">58mm</a>
                            <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => 80]) }}" data-paper-selector="80" class="{{ $paperSize === '80' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700' }} rounded-xl border px-3 py-3 text-center text-sm font-black">80mm</a>
                        </div>
                    </fieldset>

                    <button type="button" onclick="printCustomerReceipt()" class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white shadow-lg shadow-orange-500/20">
                        Print Receipt
                    </button>

                    <a href="{{ route('sales.receipt.complete', $sale) }}" data-skip-printing class="block w-full rounded-xl border border-slate-300 px-4 py-3 text-center text-sm font-black text-slate-800">
                        Skip Printing / Back to POS
                    </a>

                    <a href="{{ route('sales.receipt.complete', $sale) }}" data-start-new-sale class="block text-center text-sm font-bold text-build-orange">
                        Start New Sale
                    </a>

                    <div class="rounded-xl bg-slate-50 p-4 text-xs leading-5 text-slate-600" data-printer-help>
                        <p class="font-black text-slate-900">Recommended thermal printer settings</p>
                        <p class="mt-1">Paper: {{ $paperSize }}mm · Margins: None · Scale: 100% · Browser headers/footers: Off</p>
                        <p class="mt-2">If your browser does not return automatically after printing, use the Back to POS button above.</p>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <script>
        window.hardexReceiptReturnUrl = @js(route('sales.receipt.complete', $sale));
        window.hardexReceiptPrintStarted = false;

        window.addEventListener('afterprint', () => {
            if (! window.hardexReceiptPrintStarted) {
                return;
            }

            window.hardexReceiptPrintStarted = false;
            window.location.assign(window.hardexReceiptReturnUrl);
        });

        window.printCustomerReceipt = async function () {
            const receipt = document.getElementById('customer-receipt');
            const images = receipt ? Array.from(receipt.querySelectorAll('img')) : Array.of();

            await Promise.all(images.map((image) => new Promise((resolve) => {
                let settled = false;
                const finish = async () => {
                    if (settled) {
                        return;
                    }

                    settled = true;

                    if (image.naturalWidth) {
                        try {
                            await image.decode();
                        } catch (error) {
                            // A loaded image may not implement decode on older browsers.
                        }
                    } else {
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

            window.hardexReceiptPrintStarted = true;
            window.print();
        };
    </script>
</div>
