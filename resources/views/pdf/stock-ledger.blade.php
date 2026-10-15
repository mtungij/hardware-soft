<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('application.stock_ledger_d2698b12') }} {{ $product->sku }} · {{ $location->name }}</title>
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
@if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ __('application.company_logo_dd0cb6c1') }}">@endif
<div class="brand">{{ $company?->company_name ?? config('app.name', 'HARDEX') }}</div>
<h1>{{ __('application.stock_ledger_1bc991f9') }}</h1>
<table class="meta"><tr>
<td><span class="label">{{ __('application.product_dd3b86d1') }}</span><strong>{{ $product->displayNameWithSize() }}</strong></td>
<td><span class="label">{{ __('application.sku_unit_726a598c') }}</span>{{ $product->sku }} / {{ $product->unit?->short_name ?? '—' }}</td>
<td><span class="label">{{ __('application.stock_location_50e3c4fe') }}</span><strong>{{ $location->name }}</strong></td>
<td><span class="label">{{ __('application.current_stock_3609fc2d') }}</span><strong>{{ \App\Support\NumberFormatter::quantity($report['current']) }} {{ $product->unit?->short_name }}</strong></td>
</tr></table>
<h2>{{ __('application.latest_action_4edbbdbb') }}</h2>
@php $last = $report['latest']; @endphp
<table class="summary">
<tr>
<td><span class="label">{{ __('application.action_97c89a4d') }}</span><strong>{{ $last['action'] }}</strong></td>
<td><span class="label">{{ __('application.quantity_before_bf4011c8') }}</span>{{ $last['before'] === null ? '—' : \App\Support\NumberFormatter::quantity($last['before']) }}</td>
<td><span class="label">{{ __('application.change_64fbd995') }}</span>{{ $last['change'] === null ? '—' : (($last['change'] > 0 ? '+' : '').\App\Support\NumberFormatter::quantity($last['change'])) }}</td>
<td><span class="label">{{ __('application.quantity_after_bb5f2f75') }}</span>{{ \App\Support\NumberFormatter::quantity($last['after']) }}</td>
</tr><tr>
<td colspan="2"><span class="label">{{ __('application.reference_db1c7845') }}</span>{{ $last['reference'] }}</td>
<td colspan="2"><span class="label">{{ __('application.last_action_date_adde2bb7') }}</span>{{ $last['date']?->locale(app()->getLocale())->translatedFormat('d M Y') ?? '—' }}</td>
</tr>
</table>
<h2>{{ __('application.movement_history_698392fb') }}</h2>
<table class="history">
<thead><tr><th>{{ __('application.date_eb9a4bc1') }}</th><th>{{ __('application.action_97c89a4d') }}</th><th>{{ __('application.qty_in_daf3844b') }}</th><th>{{ __('application.qty_out_6c8d0a9e') }}</th><th>{{ __('application.stock_before_1bd3259c') }}</th><th>{{ __('application.stock_after_b51adbfc') }}</th>@if ($canViewValue)<th>{{ __('application.cost_64ae43e8') }}</th>@endif<th>{{ __('application.reference_db1c7845') }}</th><th>{{ __('application.notes_70440046') }}</th></tr></thead>
<tbody>
@forelse ($report['history'] as $entry)
<tr>
<td>{{ $entry['movement']->movement_date?->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
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
<tr><td colspan="{{ $canViewValue ? 9 : 8 }}">{{ __('application.no_ledger_movements_found_f873119e') }}</td></tr>
@endforelse
</tbody></table>
</body>
</html>
