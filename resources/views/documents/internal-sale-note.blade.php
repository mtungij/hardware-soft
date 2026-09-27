<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $valueMode ? 'Internal Sale Value Note' : 'Internal Delivery Note' }} · {{ $sale->internal_sale_number }}</title>
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
    <button onclick="window.print()">Print {{ $valueMode ? 'Value Note' : 'Delivery Note' }}</button>
    <a href="{{ $valueMode ? route('internal-sales.value-note.pdf', $sale) : route('internal-sales.delivery-note.pdf', $sale) }}">Download PDF</a>
</div>
@endif
<div class="sheet">
@if ($logo)<img class="logo" src="{{ $logo }}" alt="Company logo">@endif
<div class="brand">{{ $sale->company->company_name }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($sale->company->$field)<div class="muted">{{ $sale->company->$field }}</div>@endif
@endforeach
@if ($sale->company->tin_number)<div>TIN: {{ $sale->company->tin_number }}</div>@endif
@if ($sale->company->vrn_number)<div>VRN: {{ $sale->company->vrn_number }}</div>@endif
<h1>{{ $valueMode ? 'INTERNAL SALE VALUE NOTE' : 'INTERNAL DELIVERY NOTE' }}</h1>
<table class="metadata">
<tr><td><b>Internal Sale No: {{ $sale->internal_sale_number }}</b><br>Date: {{ $sale->sale_date->format('d M Y') }}<br>Branch: {{ $sale->branch?->name }}</td>
<td>Prepared By: {{ $sale->creator?->name ?? 'Unavailable' }}<br>Completed By: {{ $sale->completedBy?->name ?? 'Unavailable' }}
@if($sale->completed_at)<br>Completed At: {{ $sale->completed_at->format('d M Y H:i') }}@endif</td></tr>
</table>
<table class="locations"><tr>
<td><b>FROM LOCATION</b><h3>{{ $sale->fromLocation?->name }}</h3>Code: {{ $sale->fromLocation?->code }}</td>
<td><b>TO LOCATION</b><h3>{{ $sale->toLocation?->name }}</h3>Code: {{ $sale->toLocation?->code }}</td>
</tr></table>
<table class="items">
<thead><tr><th style="width:6%">#</th><th>Product</th><th>SKU</th><th>Transaction Unit</th><th class="right">Quantity</th>
@if($valueMode)<th class="right">Internal Unit Price</th><th class="right">Internal Value</th>@endif
</tr></thead>
<tbody>
@foreach($sale->items as $item)
<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product?->displayNameWithSize() ?? 'Product unavailable' }}</td><td>{{ $item->product?->sku ?? '—' }}</td><td>{{ $item->transaction_unit_name_snapshot }} ({{ $item->transaction_unit_code_snapshot }})</td><td class="right">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
@if($valueMode)<td class="right">{{ \App\Support\NumberFormatter::money($item->internal_unit_price) }}</td><td class="right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>@endif
</tr>
@endforeach
</tbody></table>
@if($valueMode)<p class="right"><b>Total Internal Value: TZS {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</b></p>@endif
@if($sale->notes)<h3>Notes</h3><div class="notes">{{ $sale->notes }}</div>@endif
<table class="signatures"><tr>
<td><b>PREPARED BY</b><p>Name: {{ $sale->creator?->name ?? '________________' }}</p><p>Signature: ________________</p><p>Date/Time: ________________</p></td>
<td><b>DISPATCHED BY</b><p>Name: ________________</p><p>Signature: ________________</p><p>Date/Time: ________________</p></td>
<td><b>RECEIVED BY</b><p>Name: ________________</p><p>Signature: ________________</p><p>Date/Time: ________________</p></td>
<td><b>RECEIVER SIGNATURE</b><p>Signature: ________________</p><p>Date/Time: ________________</p></td>
</tr></table>
<p class="muted">{{ $valueMode ? 'Internal commercial value only · no external customer sale' : 'Internal stock delivery · quantities only' }} · {{ $sale->internal_sale_number }}</p>
</div>
</body>
</html>
