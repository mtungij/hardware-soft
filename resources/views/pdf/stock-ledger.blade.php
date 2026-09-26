<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stock Ledger · {{ $product->sku }} · {{ $location->name }}</title>
<style>
body { background: #ffffff; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9pt; }
h1 { color: #172033; font-size: 18pt; margin: 0 0 4mm; border-bottom: 2px solid #ea580c; padding-bottom: 2mm; }
h2 { color: #172033; font-size: 11pt; margin: 5mm 0 2mm; }
.brand { color: #ea580c; font-size: 15pt; font-weight: bold; }
.logo { max-width: 35mm; max-height: 17mm; }
.muted { color: #475569; }
table { width: 100%; border-collapse: collapse; }
.meta td, .summary td { width: 25%; padding: 2mm; vertical-align: top; border: 1px solid #cbd5e1; }
.summary { background: #f1f5f9; margin-top: 3mm; }
.label { display: block; color: #475569; font-size: 7.5pt; }
.history th { background: #172033; color: #ffffff; text-align: left; border: 1px solid #172033; padding: 2mm; font-size: 8pt; }
.history td { border: 1px solid #cbd5e1; padding: 2mm; font-size: 8pt; vertical-align: top; }
.history tr { page-break-inside: avoid; }
.right { text-align: right; }
.positive { color: #047857; }
.negative { color: #c2410c; }
</style>
</head>
<body>
@if ($logo)<img class="logo" src="{{ $logo }}" alt="Company logo">@endif
<div class="brand">{{ $company?->company_name ?? config('app.name', 'HARDEX') }}</div>
<h1>Stock Ledger</h1>
<table class="meta"><tr>
<td><span class="label">Product</span><strong>{{ $product->displayNameWithSize() }}</strong></td>
<td><span class="label">SKU / Unit</span>{{ $product->sku }} / {{ $product->unit?->short_name ?? '—' }}</td>
<td><span class="label">Stock Location</span><strong>{{ $location->name }}</strong></td>
<td><span class="label">Current Stock</span><strong>{{ \App\Support\NumberFormatter::quantity($report['current']) }} {{ $product->unit?->short_name }}</strong></td>
</tr></table>
<h2>Latest Action</h2>
@php $last = $report['latest']; @endphp
<table class="summary">
<tr>
<td><span class="label">Action</span><strong>{{ $last['action'] }}</strong></td>
<td><span class="label">Quantity Before</span>{{ $last['before'] === null ? '—' : \App\Support\NumberFormatter::quantity($last['before']) }}</td>
<td><span class="label">Change</span>{{ $last['change'] === null ? '—' : (($last['change'] > 0 ? '+' : '').\App\Support\NumberFormatter::quantity($last['change'])) }}</td>
<td><span class="label">Quantity After</span>{{ \App\Support\NumberFormatter::quantity($last['after']) }}</td>
</tr><tr>
<td colspan="2"><span class="label">Reference</span>{{ $last['reference'] }}</td>
<td colspan="2"><span class="label">Last Action Date</span>{{ $last['date']?->format('d M Y') ?? '—' }}</td>
</tr>
</table>
<h2>Movement History</h2>
<table class="history">
<thead><tr><th>Date</th><th>Action</th><th>Qty In</th><th>Qty Out</th><th>Stock Before</th><th>Stock After</th>@if ($canViewValue)<th>Cost</th>@endif<th>Reference</th><th>Notes</th></tr></thead>
<tbody>
@forelse ($report['history'] as $entry)
<tr>
<td>{{ $entry['movement']->movement_date?->format('d M Y') }}</td>
<td>{{ $entry['action'] }}</td>
<td class="right positive">{{ $entry['change'] > 0 ? \App\Support\NumberFormatter::quantity($entry['change']) : '—' }}</td>
<td class="right negative">{{ $entry['change'] < 0 ? \App\Support\NumberFormatter::quantity(abs($entry['change'])) : '—' }}</td>
<td class="right">{{ \App\Support\NumberFormatter::quantity($entry['before']) }}</td>
<td class="right"><strong>{{ \App\Support\NumberFormatter::quantity($entry['after']) }}</strong></td>
@if ($canViewValue)<td class="right">TZS {{ \App\Support\NumberFormatter::money($entry['movement']->unit_cost) }}</td>@endif
<td>{{ $entry['reference'] }}</td>
<td>{{ $entry['movement']->notes ?: '—' }}</td>
</tr>
@empty
<tr><td colspan="{{ $canViewValue ? 9 : 8 }}">No ledger movements found.</td></tr>
@endforelse
</tbody></table>
</body>
</html>
