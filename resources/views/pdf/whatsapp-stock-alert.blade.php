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
<h1>LOW / OUT OF STOCK REPORT</h1>
<div><strong>{{ $company->company_name }}</strong></div>
<div class="meta">
    Scope: {{ $scopeLabel }}<br>
    Generated: {{ $generatedAt->format('d M Y H:i') }}
</div>

<table class="summary"><tr>
    <td>Total requiring attention<strong>{{ $rows->count() }}</strong></td>
    <td>Out of Stock<strong>{{ $rows->where('status', 'OUT OF STOCK')->count() }}</strong></td>
    <td>Low Stock<strong>{{ $rows->where('status', 'LOW STOCK')->count() }}</strong></td>
</tr></table>

<table class="detail">
    <thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Branch</th><th>Stock Location</th><th class="number">Current Quantity</th><th>Unit</th><th class="number">Reorder Level</th><th>Status</th></tr></thead>
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
            <td class="{{ $row->status === 'OUT OF STOCK' ? 'status-out' : 'status-low' }}">{{ $row->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
