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
<h2>{{ __('application.material_issue_receipt_4eff12e0') }}</h2>
<div class="rule"></div>
<table class="meta">
    <tr><td class="label">{{ __('application.reference_45c3dc1c') }}</td><td>{{ $issue->reference_number }}</td></tr>
    <tr><td class="label">{{ __('application.date_81b7d2ea') }}</td><td>{{ $issue->issued_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</td></tr>
    <tr><td class="label">{{ __('application.customer_5e0d7363') }}</td><td>{{ $account->customer->displayName() }}</td></tr>
    <tr><td class="label">{{ __('application.project_account_ad9249ae') }}</td><td>{{ $account->reference_number }}</td></tr>
    <tr><td class="label">{{ __('application.branch_8b31d33d') }}</td><td>{{ $issue->branch->name }}</td></tr>
    <tr><td class="label">{{ __('application.stock_location_980f6a5e') }}</td><td>{{ $issue->stockLocation->name }}</td></tr>
</table>
<div class="rule"></div>
<div><strong>{{ __('application.materials_279488a2') }}</strong></div>
@foreach($issue->lines as $line)
    <div class="item">
        <div class="item-name">{{ $line->product_name_snapshot }}</div>
        <div class="item-value">{{ \App\Support\NumberFormatter::quantity($line->quantity) }} {{ $line->unit_code_snapshot }} × TZS {{ \App\Support\NumberFormatter::money($line->agreed_unit_price) }} = TZS {{ \App\Support\NumberFormatter::money($line->line_value) }}</div>
    </div>
@endforeach
<div class="rule"></div>
<table class="totals">
    <tr><td>{{ __('application.total_material_value_e0a78eea') }}</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($issue->total_value) }}</td></tr>
    <tr><td>{{ __('application.previous_funded_balance_6c046b86') }}</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($previousBalance) }}</td></tr>
    <tr><td>{{ __('application.amount_used_f4d35832') }}</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($issue->total_value) }}</td></tr>
    <tr><td>{{ __('application.remaining_funded_balance_c7a8c6dd') }}</td><td class="amount">TZS {{ \App\Support\NumberFormatter::money($remainingBalance) }}</td></tr>
</table>
<div class="rule"></div>
<table class="meta">
    <tr><td class="label">{{ __('application.collected_by_4e9af775') }}</td><td>{{ $issue->collected_by ?: '-' }}</td></tr>
    <tr><td class="label">{{ __('application.issued_by_aba2d603') }}</td><td>{{ $issue->issuedBy?->name ?: '-' }}</td></tr>
    @if($issue->notes)<tr><td class="label">{{ __('application.notes_9c3befe7') }}</td><td>{{ $issue->notes }}</td></tr>@endif
</table>
<div class="footer">{{ $account->company?->company_name ?: 'HARDEX POS' }}</div>
</body>
</html>
