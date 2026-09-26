<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stock Transfer Note · {{ $transfer->transfer_number }}</title>
<style>
@if (! ($isPdf ?? false))
@page { size: A4; margin: 15mm; }
@endif
body { font-family: sans-serif; color: #172033; font-size: 10pt; background: white; }
.sheet { max-width: 180mm; margin: auto; }
.brand { color: #ea580c; font-size: 22pt; font-weight: bold; }
.logo { max-width: 45mm; max-height: 20mm; }
h1 { font-size: 22pt; border-bottom: 3px solid #ea580c; padding-bottom: 4mm; }
.muted { color: #64748b; }
table { width: 100%; border-collapse: collapse; }
.metadata td, .locations td { width: 50%; vertical-align: top; padding: 3mm; }
.locations { background: #f1f5f9; margin: 5mm 0; }
.items { table-layout: fixed; }
.items th { background: #172033; color: white; text-align: left; }
.items th, .items td { border: 1px solid #cbd5e1; padding: 2.5mm; overflow-wrap: anywhere; word-wrap: break-word; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
.right { text-align: right; }
.summary { margin: 4mm 0; text-align: right; }
.notes { white-space: pre-wrap; overflow-wrap: anywhere; }
.signatures { margin-top: 10mm; page-break-inside: avoid; }
.signatures td { width: 33%; padding: 3mm; vertical-align: top; }
.signatures p { margin-top: 7mm; }
.toolbar { margin: 20px auto; max-width: 180mm; }
.toolbar button, .toolbar a { padding: 10px 16px; }
@media print { .toolbar { display: none; } body { margin: 0; } .sheet { max-width: none; } }
</style>
</head>
<body>
@if (!($isPdf ?? false))
<div class="toolbar"><button onclick="window.print()">Print Transfer Note</button> <a href="{{ route('stock-transfers.note.pdf', $transfer) }}">Download PDF</a></div>
@endif
<div class="sheet">
@if ($logo)<img class="logo" src="{{ $logo }}" alt="Company logo">@endif
<div class="brand">{{ $transfer->company->company_name }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($transfer->company->$field)<div class="muted">{{ $transfer->company->$field }}</div>@endif
@endforeach
@if ($transfer->company->tin_number)<div>TIN: {{ $transfer->company->tin_number }}</div>@endif
@if ($transfer->company->vrn_number)<div>VRN: {{ $transfer->company->vrn_number }}</div>@endif
<h1>STOCK TRANSFER NOTE</h1>
<table class="metadata">
<tr><td><b>Transfer No: {{ $transfer->transfer_number }}</b><br>Transfer Date: {{ $transfer->transfer_date->format('d M Y') }}<br>Status: Completed</td>
<td>Branch: {{ $transfer->branch?->name ?? 'Unavailable' }}<br>Prepared By: {{ $transfer->createdBy?->name ?? 'Unavailable' }}<br>Completed By: {{ $transfer->completedBy?->name ?? 'Unavailable' }}
@if ($transfer->completed_at)<br>Completed At: {{ $transfer->completed_at->format('d M Y H:i') }}@endif</td></tr>
</table>
<table class="locations"><tr>
@foreach (['FROM LOCATION' => $transfer->fromLocation, 'TO LOCATION' => $transfer->toLocation] as $label => $location)
<td><b>{{ $label }}</b><h3>{{ $location?->name ?? 'Unavailable' }}</h3>
Code: {{ $location?->code ?? 'Unavailable' }}<br>
Type: {{ \App\Models\StockLocation::TYPES[$location?->type] ?? str($location?->type ?? 'Unavailable')->replace('_', ' ')->title() }}<br>
Branch: {{ $location?->branch?->name ?? 'Company-wide' }}</td>
@endforeach
</tr></table>
<table class="items">
<thead><tr><th style="width:5%">#</th><th style="width:43%">Product</th><th style="width:22%">SKU</th><th style="width:13%">Unit</th><th style="width:17%">Quantity</th></tr></thead>
<tbody>
@foreach ($transfer->items as $item)
<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product?->displayNameWithSize() ?? 'Product unavailable' }}</td><td>{{ $item->product?->sku ?? '—' }}</td><td>{{ $item->product?->unit?->short_name ?? 'Unavailable' }}</td><td class="right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }}</td></tr>
@endforeach
</tbody></table>
<div class="summary"><b>Total Product Lines: {{ $transfer->items->count() }}</b>
@foreach ($totals as $total)<div>Total Quantity ({{ $total['unit'] }}): {{ \App\Support\NumberFormatter::quantity($total['quantity']) }}</div>@endforeach
</div>
@if ($transfer->notes)<h3>Reason / Notes</h3><div class="notes">{{ $transfer->notes }}</div>@endif
<table class="signatures"><tr>
@foreach (['PREPARED BY', 'RELEASED BY', 'RECEIVED BY'] as $label)
<td><b>{{ $label }}</b><p>Name: {{ $loop->first ? ($transfer->createdBy?->name ?? '________________') : '________________' }}</p><p>Signature: ________________</p><p>Date: ___________________</p></td>
@endforeach
</tr></table>
<p class="muted">Internal stock movement · {{ $transfer->transfer_number }}</p>
</div>
</body>
</html>
