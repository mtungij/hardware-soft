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
<div class="brand">{{ $quotation->company->company_name }}</div>
<div class="muted">{{ $quotation->company->address }} · {{ $quotation->company->phone }} · {{ $quotation->company->email }}</div>
<h1>{{ $quotation->document_type === 'proforma' ? __('terminology.proforma_invoice_21b03d8b') : __('application.quotation_a03402d0') }}</h1>
<table class="grid">
<tr>
<td>
<b>{{ $quotation->quotation_number }}</b>
<br>{{ __('application.date_81b7d2ea') }} {{ $quotation->quotation_date->locale(app()->getLocale())->translatedFormat('d M Y') }}<br>{{ __('application.valid_until_a4838c38') }} {{ $quotation->valid_until->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
<td>
<b>{{ __('application.customer_0e85749a') }}</b>
<br>{{ $quotation->customer->displayName() }}<br>{{ $quotation->customer->phone }}<br>{{ $quotation->customer->address }}</td>
</tr>
</table>
<table class="items">
<thead>
<tr>
<th>#</th>
<th>{{ __('application.product_dd3b86d1') }}</th>
<th>{{ __('application.unit_f6b935ab') }}</th>
<th class="right">{{ __('application.quantity_44f6af69') }}</th>
<th class="right">{{ __('application.unit_price_1eea68d0') }}</th>
<th class="right">{{ __('application.discount_b524936d') }}</th>
<th class="right">{{ __('application.total_b25928c6') }}</th>
</tr>
</thead>
<tbody>
@foreach($quotation->items as $index => $item)<tr>
<td>{{ $index+1 }}</td>
<td>{{ $item->product_name_snapshot }}</td>
<td>{{ $item->transaction_unit_name_snapshot }}</td>
<td class="right">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->discount_amount) }}</td>
<td class="right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
</tr>@endforeach
</tbody>
</table>
<table class="summary">
<tr>
<td>{{ __('application.subtotal_97f7359e') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($quotation->subtotal) }}</td>
</tr>
<tr>
<td>{{ __('application.discount_b524936d') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($quotation->discount_amount) }}</td>
</tr>
<tr>
<td>{{ __('application.tax_9be70f66') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($quotation->tax_amount) }}</td>
</tr>@foreach($quotation->additionalCharges as $charge)<tr>
<td>{{ $charge->charge_name_snapshot }}@if($charge->description_snapshot)<br><span class="muted">{{ $charge->description_snapshot }}</span>@endif</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</td>
</tr>@endforeach<tr class="total">
<td>{{ __('application.grand_total_91faafb1') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</td>
</tr>
</table>
@if($quotation->notes)<div class="section">{{ __('application.notes_70440046') }}</div>
<div>{{ $quotation->notes }}</div>@endif
@if($quotation->terms)<div class="section">{{ __('application.terms_a55a275a') }}</div>
<div>{{ $quotation->terms }}</div>@endif
@if($paymentMethods->isNotEmpty())<div class="section">{{ __('application.payment_instructions_f3fa021f') }}</div>
<div class="muted">{{ __('application.use_payment_reference_e3549df1') }} <b>{{ $quotation->quotation_number }}</b>
</div>@foreach($paymentMethods as $method)<div style="margin-top:2mm">
<b>{{ $method->display_name }}</b>@if($method->provider) · {{ $method->provider }}@endif@if($method->bank_name) · {{ $method->bank_name }}@endif<br>@if($method->account_name){{ __('application.account_name_3e29c383') }} {{ $method->account_name }}<br>@endif @if($method->account_number){{ __('application.account_ac06ef9e') }} {{ $method->account_number }}<br>@endif @if($method->phone_or_business_number){{ __('application.number_73089985') }} {{ $method->phone_or_business_number }}<br>@endif @if($method->branch_name){{ __('application.branch_8b31d33d') }} {{ $method->branch_name }}<br>@endif @if($method->instructions){{ $method->instructions }}@endif</div>@endforeach @endif
<div class="section">{{ __('application.prepared_by_447abf81') }}</div>
<div>{{ $quotation->creator->name }}</div>
</body>
</html>
