<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
body{font-family:sans-serif;color:#172033;font-size:9pt}.brand{color:#f97316;font-size:22pt;font-weight:bold}.muted{color:#64748b}.grid{width:100%;margin:5mm 0}.grid td{vertical-align:top;width:50%}table.items{border-collapse:collapse;width:100%}.items th{background:#172033;color:white;padding:2.5mm;text-align:left}.items td{border-bottom:1px solid #e5e7eb;padding:2.5mm}.right{text-align:right}.summary{margin-left:auto;margin-top:5mm;width:48%}.summary td{padding:1.5mm}.total{font-size:12pt;font-weight:bold;border-top:2px solid #172033}.section{margin-top:6mm;font-weight:bold}
</style>
</head>
<body>
@php($paymentMethods=$paymentMethods??collect())
@php($sale=$invoice->sale)
<div class="brand">{{ $invoice->company->company_name }}</div>
<div class="muted">{{ $invoice->company->address }} · {{ $invoice->company->phone }} · {{ $invoice->company->email }}</div>
<h1>SALES INVOICE</h1>
<table class="grid">
<tr>
<td>
<b>Invoice: {{ $invoice->invoice_number }}</b>
<br>Sale: {{ $sale->sale_number }}<br>Date: {{ $sale->sale_date->format('d M Y') }}<br>Branch: {{ $sale->branch->name }}</td>
<td>
<b>Customer</b>
<br>{{ $invoice->customer?->name ?: 'Walk-in Customer' }}<br>{{ $invoice->customer?->phone }}<br>{{ $invoice->customer?->address }}</td>
</tr>
</table>
<table class="items">
<thead>
<tr>
<th>#</th>
<th>Product</th>
<th>Unit</th>
<th class="right">Quantity</th>
<th class="right">Unit Price</th>
<th class="right">Discount</th>
<th class="right">Total</th>
</tr>
</thead>
<tbody>
@foreach($sale->items as $index => $item)<tr>
<td>{{ $index+1 }}</td>
<td>{{ $item->product?->displayNameWithSize() }}</td>
<td>{{ $item->selling_unit_code_snapshot ?: $item->base_unit_code_snapshot }}</td>
<td class="right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->discount_total ?: $item->discount_amount) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
</tr>@endforeach
</tbody>
</table>
<table class="summary">
<tr>
<td>Subtotal</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->subtotal) }}</td>
</tr>
<tr>
<td>Discount</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</td>
</tr>
<tr>
<td>Tax</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</td>
</tr>@foreach($sale->additionalCharges as $charge)<tr>
<td>{{ $charge->charge_name_snapshot }}@if($charge->description_snapshot)<br><span class="muted">{{ $charge->description_snapshot }}</span>@endif</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</td>
</tr>@endforeach<tr class="total">
<td>Grand Total</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</td>
</tr>
<tr>
<td>Paid</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</td>
</tr>
<tr>
<td>Balance</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</td>
</tr>
</table>
<div class="section">Payment Method(s)</div>
<div>{{ $sale->payments->pluck('payment_method')->map(fn($method)=>str($method)->headline())->join(', ') ?: 'Credit / Outstanding' }}</div>@if($paymentMethods->isNotEmpty())<div class="section">Payment Instructions</div>
<div class="muted">Use payment reference: <b>{{ $invoice->invoice_number }}</b>
</div>@foreach($paymentMethods as $method)<div style="margin-top:2mm">
<b>{{ $method->display_name }}</b>@if($method->provider) · {{ $method->provider }}@endif@if($method->bank_name) · {{ $method->bank_name }}@endif<br>@if($method->account_name)Account name: {{ $method->account_name }}<br>@endif @if($method->account_number)Account: {{ $method->account_number }}<br>@endif @if($method->phone_or_business_number)Number: {{ $method->phone_or_business_number }}<br>@endif @if($method->branch_name)Branch: {{ $method->branch_name }}<br>@endif @if($method->instructions){{ $method->instructions }}@endif</div>@endforeach @endif<div class="section">Prepared By</div>
<div>{{ $sale->createdBy->name }}</div>
</body>
</html>
