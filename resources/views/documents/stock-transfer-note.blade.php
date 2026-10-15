<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('application.stock_transfer_note_19b46fc7') }} {{ $transfer->transfer_number }}</title>
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
<div class="toolbar"><button onclick="window.print()">{{ __('application.print_transfer_note_d55a844e') }}</button> <a href="{{ route('stock-transfers.note.pdf', $transfer) }}">{{ __('application.download_pdf_98e5ef06') }}</a></div>
@endif
<div class="sheet">
@if ($logo)<img class="logo" src="{{ $logo }}" alt="{{ __('application.company_logo_dd0cb6c1') }}">@endif
<div class="brand">{{ $transfer->company->company_name }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($transfer->company->$field)<div class="muted">{{ $transfer->company->$field }}</div>@endif
@endforeach
@if ($transfer->company->tin_number)<div>TIN: {{ $transfer->company->tin_number }}</div>@endif
@if ($transfer->company->vrn_number)<div>VRN: {{ $transfer->company->vrn_number }}</div>@endif
<h1>{{ __('application.stock_transfer_note_68cb17c0') }}</h1>
<table class="metadata">
<tr><td><b>{{ __('application.transfer_no_d89691db') }} {{ $transfer->transfer_number }}</b><br>{{ __('application.transfer_date_e95671a7') }} {{ $transfer->transfer_date->locale(app()->getLocale())->translatedFormat('d M Y') }}<br>{{ __('application.status_completed_518b0e66') }}</td>
<td>{{ __('application.branch_8b31d33d') }} {{ $transfer->branch?->name ?? __('terminology.unavailable_2c9c1f79') }}<br>{{ __('application.prepared_by_a453ec56') }} {{ $transfer->createdBy?->name ?? __('terminology.unavailable_2c9c1f79') }}<br>{{ __('application.completed_by_5e807458') }} {{ $transfer->completedBy?->name ?? __('terminology.unavailable_2c9c1f79') }}
@if ($transfer->completed_at)<br>{{ __('application.completed_at_555fe005') }} {{ $transfer->completed_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}@endif</td></tr>
</table>
<table class="locations"><tr>
@foreach (['FROM LOCATION' => $transfer->fromLocation, 'TO LOCATION' => $transfer->toLocation] as $label => $location)
<td><b>{{ $label }}</b><h3>{{ $location?->name ?? __('terminology.unavailable_2c9c1f79') }}</h3>
{{ __('application.code_ca567dda') }} {{ $location?->code ?? __('terminology.unavailable_2c9c1f79') }}<br>
{{ __('application.type_ee3fb11d') }} {{ \App\Models\StockLocation::TYPES[$location?->type] ?? \App\Support\UiText::label($location?->type ?? __('terminology.unavailable_2c9c1f79')) }}<br>
{{ __('application.branch_8b31d33d') }} {{ $location?->branch?->name ?? __('application.company_wide_24f7fca3') }}</td>
@endforeach
</tr></table>
<table class="items">
<thead><tr><th style="width:5%">#</th><th style="width:43%">{{ __('application.product_dd3b86d1') }}</th><th style="width:22%">{{ __('application.sku_d59192b9') }}</th><th style="width:13%">{{ __('application.unit_f6b935ab') }}</th><th style="width:17%">{{ __('application.quantity_44f6af69') }}</th></tr></thead>
<tbody>
@foreach ($transfer->items as $item)
<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product?->displayNameWithSize() ?? __('terminology.product_unavailable_226abb6d') }}</td><td>{{ $item->product?->sku ?? '—' }}</td><td>{{ $item->product?->unit?->short_name ?? __('terminology.unavailable_2c9c1f79') }}</td><td class="right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }}</td></tr>
@endforeach
</tbody></table>
<div class="summary"><b>{{ __('application.total_product_lines_4a5b21fa') }} {{ $transfer->items->count() }}</b>
@foreach ($totals as $total)<div>{{ __('application.total_quantity_bfd129e5') }}{{ $total['unit'] }}): {{ \App\Support\NumberFormatter::quantity($total['quantity']) }}</div>@endforeach
</div>
@if ($transfer->notes)<h3>{{ __('application.reason_notes_10393150') }}</h3><div class="notes">{{ $transfer->notes }}</div>@endif
<table class="signatures"><tr>
@foreach (['PREPARED BY', 'RELEASED BY', 'RECEIVED BY'] as $label)
<td><b>{{ $label }}</b><p>{{ __('application.name_71dd2eff') }} {{ $loop->first ? ($transfer->createdBy?->name ?? '________________') : '________________' }}</p><p>{{ __('application.signature_cb48389d') }}</p><p>{{ __('application.date_16ffa441') }}</p></td>
@endforeach
</tr></table>
<p class="muted">{{ __('application.internal_stock_movement_344e5a8f') }} {{ $transfer->transfer_number }}</p>
</div>
</body>
</html>
