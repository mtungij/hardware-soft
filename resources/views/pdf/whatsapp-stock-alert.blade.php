<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #172033; font-family: sans-serif; font-size: 8.5pt; }
        h1 { color: #f97316; font-size: 19pt; margin: 0 0 1.5mm; }
        .meta { color: #64748b; font-size: 9pt; }
        .summary { margin: 6mm 0; width: 100%; }
        .summary td { background: #f8fafc; border: 1px solid #e2e8f0; padding: 3mm; width: 33.33%; }
        .summary strong { display: block; font-size: 15pt; margin-top: 1mm; }
        .detail { border-collapse: collapse; width: 100%; }
        .detail th { background: #172033; color: #fff; font-size: 7.5pt; padding: 2.2mm 1.5mm; text-align: left; }
        .detail td { border-bottom: 1px solid #e5e7eb; padding: 2mm 1.5mm; vertical-align: top; }
        .number { text-align: right; white-space: nowrap; }
        .status-out { color: #b91c1c; font-weight: bold; }
        .status-low { color: #b45309; font-weight: bold; }
    </style>
</head>
<body>
<h1>{{ __('application.low_out_of_stock_report_b8057ecf') }}</h1>
<div><strong>{{ $company->company_name }}</strong></div>
<div class="meta">
    {{ __('application.scope_8402f050') }} {{ $scopeLabel }}<br>
    {{ __('application.generated_a2edf57c') }} {{ $generatedAt->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}
</div>

<table class="summary"><tr>
    <td>{{ __('application.total_requiring_attention_2b83e1f8') }}<strong>{{ $rows->count() }}</strong></td>
    <td>{{ __('application.out_of_stock_bc67a8ae') }}<strong>{{ $rows->where('status', 'OUT OF STOCK')->count() }}</strong></td>
    <td>{{ __('application.low_stock_023d1181') }}<strong>{{ $rows->where('status', 'LOW STOCK')->count() }}</strong></td>
</tr></table>

<table class="detail">
    <thead><tr><th>#</th><th>{{ __('application.product_dd3b86d1') }}</th><th>{{ __('application.sku_d59192b9') }}</th><th>{{ __('application.branch_1627510b') }}</th><th>{{ __('application.stock_location_50e3c4fe') }}</th><th class="number">{{ __('application.current_quantity_f065f1bb') }}</th><th>{{ __('application.unit_f6b935ab') }}</th><th class="number">{{ __('application.reorder_level_59ef31e3') }}</th><th>{{ __('application.status_bae7d5be') }}</th></tr></thead>
    <tbody>
    @foreach($rows as $index => $row)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $row->name }}</td>
            <td>{{ $row->sku ?: '—' }}</td>
            <td>{{ $row->branch ?: '—' }}</td>
            <td>{{ $row->location }}</td>
            <td class="number">{{ \App\Support\NumberFormatter::quantity($row->quantity) }}</td>
            <td>{{ $row->unit }}</td>
            <td class="number">{{ \App\Support\NumberFormatter::quantity($row->reorder_level) }}</td>
            <td class="{{ $row->status === 'OUT OF STOCK' ? 'status-out' : 'status-low' }}">{{ \App\Support\UiText::status($row->status) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
