<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #000; font-family: sans-serif; font-size: 10pt; line-height: 1.35; }
        h1 { font-size: 13pt; margin: 0; text-align: center; }
        h2 { font-size: 11pt; margin: 2mm 0 4mm; text-align: center; }
        .rule { border-top: 1px dashed #000; margin: 3mm 0; }
        .meta { width: 100%; }
        .meta td { padding: 0.7mm 0; vertical-align: top; }
        .label { font-weight: bold; width: 38%; }
        .item { margin: 0 0 3mm; }
        .item-name { font-weight: bold; }
        .item-value { text-align: right; }
        .totals { width: 100%; }
        .totals td { padding: 0.8mm 0; }
        .amount { font-weight: bold; text-align: right; white-space: nowrap; }
        .footer { font-weight: bold; margin-top: 5mm; text-align: center; }
    </style>
</head>
<body>
<h1>{{ $account->company?->company_name ?: 'HARDEX POS' }}</h1>
<h2>MATERIAL ISSUE RECEIPT / RISITI YA UTOAJI BIDHAA</h2>
<div class="rule"></div>
<table class="meta">
    <tr><td class="label">Reference:</td><td>{{ $issue->reference_number }}</td></tr>
    <tr><td class="label">Date:</td><td>{{ $issue->issued_at->format('d M Y H:i') }}</td></tr>
    <tr><td class="label">Customer:</td><td>{{ $account->customer->name }}</td></tr>
    <tr><td class="label">Project / Account:</td><td>{{ $account->reference_number }}</td></tr>
    <tr><td class="label">Branch:</td><td>{{ $issue->branch->name }}</td></tr>
    <tr><td class="label">Stock Location:</td><td>{{ $issue->stockLocation->name }}</td></tr>
</table>
<div class="rule"></div>
<div><strong>Materials / Bidhaa:</strong></div>
@foreach($issue->lines as $line)
    <div class="item">
        <div class="item-name">{{ $line->product_name_snapshot }}</div>
        <div class="item-value">{{ \App\Support\NumberFormatter::quantity($line->quantity) }} {{ $line->unit_code_snapshot }} × TZS {{ \App\Support\NumberFormatter::money($line->agreed_unit_price) }} = TZS {{ \App\Support\NumberFormatter::money($line->line_value) }}</div>
    </div>
@endforeach
<div class="rule"></div>
<table class="totals">
    <tr><td>Total Material Value:</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($issue->total_value) }}</td></tr>
    <tr><td>Previous Funded Balance:</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($previousBalance) }}</td></tr>
    <tr><td>Amount Used:</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($issue->total_value) }}</td></tr>
    <tr><td>Remaining Funded Balance:</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($remainingBalance) }}</td></tr>
</table>
<div class="rule"></div>
<table class="meta">
    <tr><td class="label">Collected By:</td><td>{{ $issue->collected_by ?: '-' }}</td></tr>
    <tr><td class="label">Issued By:</td><td>{{ $issue->issuedBy?->name ?: '-' }}</td></tr>
    @if($issue->notes)<tr><td class="label">Notes:</td><td>{{ $issue->notes }}</td></tr>@endif
</table>
<div class="footer">{{ $account->company?->company_name ?: 'HARDEX POS' }}</div>
</body>
</html>
