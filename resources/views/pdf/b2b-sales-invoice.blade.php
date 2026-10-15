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
<h1>{{ __('application.sales_invoice_ee15f244') }}</h1>
<table class="grid">
<tr>
<td>
<b>{{ __('application.invoice_2706e3ea') }} {{ $invoice->invoice_number }}</b>
<br>{{ __('application.sale_32549b53') }} {{ $sale->sale_number }}<br>{{ __('application.date_81b7d2ea') }} {{ $sale->sale_date->locale(app()->getLocale())->translatedFormat('d M Y') }}<br>{{ __('sale_dates.recorded_at') }} {{ $sale->created_at?->clone()->timezone(app(\App\Support\PosSaleDate::class)->timezone($sale->company_id))->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}<br>{{ __('application.branch_8b31d33d') }} {{ $sale->branch->name }}</td>
<td>
<b>{{ __('application.customer_0e85749a') }}</b>
<br>{{ $invoice->customer?->displayName() ?: __('application.walk_in_customer_90cd7712') }}<br>{{ $invoice->customer?->phone }}<br>{{ $invoice->customer?->address }}</td>
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
<td>{{ __('application.subtotal_97f7359e') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->subtotal) }}</td>
</tr>
<tr>
<td>{{ __('application.discount_b524936d') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</td>
</tr>
<tr>
<td>{{ __('application.tax_9be70f66') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</td>
</tr>@foreach($sale->additionalCharges as $charge)<tr>
<td>{{ $charge->charge_name_snapshot }}@if($charge->description_snapshot)<br><span class="muted">{{ $charge->description_snapshot }}</span>@endif</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</td>
</tr>@endforeach<tr class="total">
<td>{{ __('application.grand_total_91faafb1') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</td>
</tr>
<tr>
<td>{{ __('application.paid_dc9d4584') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</td>
</tr>
<tr>
<td>{{ __('application.balance_90eef613') }}</td>
<td class="right">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</td>
</tr>
</table>
<div class="section">{{ __('application.payment_method_s_dc381eaa') }}</div>
<div>{{ $sale->payments->pluck('payment_method')->map(fn($method)=>\App\Support\UiText::label($method))->join(', ') ?: __('application.credit_outstanding_c575826e') }}</div>@if($paymentMethods->isNotEmpty())<div class="section">{{ __('application.payment_instructions_f3fa021f') }}</div>
<div class="muted">{{ __('application.use_payment_reference_e3549df1') }} <b>{{ $invoice->invoice_number }}</b>
</div>@foreach($paymentMethods as $method)<div style="margin-top:2mm">
<b>{{ $method->display_name }}</b>@if($method->provider) · {{ $method->provider }}@endif@if($method->bank_name) · {{ $method->bank_name }}@endif<br>@if($method->account_name){{ __('application.account_name_3e29c383') }} {{ $method->account_name }}<br>@endif @if($method->account_number){{ __('application.account_ac06ef9e') }} {{ $method->account_number }}<br>@endif @if($method->phone_or_business_number){{ __('application.number_73089985') }} {{ $method->phone_or_business_number }}<br>@endif @if($method->branch_name){{ __('application.branch_8b31d33d') }} {{ $method->branch_name }}<br>@endif @if($method->instructions){{ $method->instructions }}@endif</div>@endforeach @endif<div class="section">{{ __('application.prepared_by_447abf81') }}</div>
<div>{{ $sale->createdBy->name }}</div>
</body>
</html>
