<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $valueMode ? __('application.internal_sale_value_note_e7b28fc9') : __('terminology.internal_delivery_note_1599cded') }} · {{ $sale->internal_sale_number }}</title>
<style>
@if (! ($isPdf ?? false))
@page { size: A4; margin: 15mm; }
@endif
body { font-family: sans-serif; color: #172033; font-size: 10pt; background: white; }
.sheet { max-width: 180mm; margin: auto; }
.brand { color: #ea580c; font-size: 22pt; font-weight: bold; }
.logo { max-width: 45mm; max-height: 20mm; }
h1 { font-size: 21pt; border-bottom: 3px solid #ea580c; padding-bottom: 4mm; }
.muted { color: #64748b; }
table { width: 100%; border-collapse: collapse; }
.metadata td { width: 50%; vertical-align: top; padding: 3mm; }
.locations { background: #f1f5f9; margin: 5mm 0; }
.locations td { width: 50%; vertical-align: top; padding: 3mm; }
.items { table-layout: fixed; }
.items th { background: #172033; color: white; text-align: left; }
.items th, .items td { border: 1px solid #cbd5e1; padding: 2.5mm; overflow-wrap: anywhere; word-wrap: break-word; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
.right { text-align: right; }
.notes { white-space: pre-wrap; overflow-wrap: anywhere; }
.signatures { margin-top: 10mm; page-break-inside: avoid; }
.signatures td { width: 25%; padding: 3mm; vertical-align: top; }
.signatures p { margin-top: 7mm; }
.toolbar { margin: 20px auto; max-width: 180mm; }
.toolbar button, .toolbar a { padding: 10px 16px; }
@media print { .toolbar { display: none; } body { margin: 0; } .sheet { max-width: none; } }
</style>
</head>
<body>
@if (! ($isPdf ?? false))
<div class="toolbar">
    <button onclick="window.print()">{{ __('application.print_5b221e9c') }} {{ $valueMode ? __('terminology.value_note_b09a4da3') : __('application.delivery_note_da6ce2cc') }}</button>
    <a href="{{ $valueMode ? route('internal-sales.value-note.pdf', $sale) : route('internal-sales.delivery-note.pdf', $sale) }}">{{ __('application.download_pdf_98e5ef06') }}</a>
</div>
@endif
<div class="sheet">
@if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ __('application.company_logo_dd0cb6c1') }}">@endif
<div class="brand">{{ $sale->company->company_name }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($sale->company->$field)<div class="muted">{{ $sale->company->$field }}</div>@endif
@endforeach
@if ($sale->company->tin_number)<div>TIN: {{ $sale->company->tin_number }}</div>@endif
@if ($sale->company->vrn_number)<div>VRN: {{ $sale->company->vrn_number }}</div>@endif
<h1>{{ $valueMode ? 'INTERNAL SALE VALUE NOTE' : 'INTERNAL DELIVERY NOTE' }}</h1>
<table class="metadata">
<tr><td><b>{{ __('application.internal_sale_no_a2649681') }} {{ $sale->internal_sale_number }}</b><br>{{ __('application.date_81b7d2ea') }} {{ $sale->sale_date->locale(app()->getLocale())->translatedFormat('d M Y') }}<br>{{ __('application.branch_8b31d33d') }} {{ $sale->branch?->name }}</td>
<td>{{ __('application.prepared_by_a453ec56') }} {{ $sale->creator?->name ?? __('terminology.unavailable_2c9c1f79') }}<br>{{ __('application.completed_by_5e807458') }} {{ $sale->completedBy?->name ?? __('terminology.unavailable_2c9c1f79') }}
@if($sale->completed_at)<br>{{ __('application.completed_at_555fe005') }} {{ $sale->completed_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}@endif</td></tr>
</table>
<table class="locations"><tr>
<td><b>{{ __('application.from_location_0131e58f') }}</b><h3>{{ $sale->fromLocation?->name }}</h3>{{ __('application.code_ca567dda') }} {{ $sale->fromLocation?->code }}</td>
<td><b>{{ __('application.to_location_9dc089f6') }}</b><h3>{{ $sale->toLocation?->name }}</h3>{{ __('application.code_ca567dda') }} {{ $sale->toLocation?->code }}</td>
</tr></table>
<table class="items">
<thead><tr><th style="width:6%">#</th><th>{{ __('application.product_dd3b86d1') }}</th><th>{{ __('application.sku_d59192b9') }}</th><th>{{ __('application.transaction_unit_19ffe8aa') }}</th><th class="right">{{ __('application.quantity_44f6af69') }}</th>
@if($valueMode)<th class="right">{{ __('application.internal_unit_price_a9671639') }}</th><th class="right">{{ __('application.internal_value_e833cb1b') }}</th>@endif
</tr></thead>
<tbody>
@foreach($sale->items as $item)
<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product?->displayNameWithSize() ?? __('terminology.product_unavailable_226abb6d') }}</td><td>{{ $item->product?->sku ?? '—' }}</td><td>{{ $item->transaction_unit_name_snapshot }} ({{ $item->transaction_unit_code_snapshot }})</td><td class="right">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
@if($valueMode)<td class="right">{{ \App\Support\NumberFormatter::money($item->internal_unit_price) }}</td><td class="right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>@endif
</tr>
@endforeach
</tbody></table>
@if($valueMode)<p class="right"><b>{{ __('application.total_internal_value_tzs_061740d2') }} {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</b></p>@endif
@if($sale->notes)<h3>{{ __('application.notes_70440046') }}</h3><div class="notes">{{ $sale->notes }}</div>@endif
<table class="signatures"><tr>
<td><b>{{ __('application.prepared_by_474ca9e3') }}</b><p>{{ __('application.name_71dd2eff') }} {{ $sale->creator?->name ?? '________________' }}</p><p>{{ __('application.signature_cb48389d') }}</p><p>{{ __('application.date_time_de5fd076') }}</p></td>
<td><b>{{ __('application.dispatched_by_3425eefe') }}</b><p>{{ __('application.name_5132d56c') }}</p><p>{{ __('application.signature_cb48389d') }}</p><p>{{ __('application.date_time_de5fd076') }}</p></td>
<td><b>{{ __('application.received_by_2778e855') }}</b><p>{{ __('application.name_5132d56c') }}</p><p>{{ __('application.signature_cb48389d') }}</p><p>{{ __('application.date_time_de5fd076') }}</p></td>
<td><b>{{ __('application.receiver_signature_b7960438') }}</b><p>{{ __('application.signature_cb48389d') }}</p><p>{{ __('application.date_time_de5fd076') }}</p></td>
</tr></table>
<p class="muted">{{ $valueMode ? __('terminology.internal_commercial_value_only_no_external_customer_sale_2c1f6d7a') : __('terminology.internal_stock_delivery_quantities_only_05cc4cfc') }} · {{ $sale->internal_sale_number }}</p>
</div>
</body>
</html>
