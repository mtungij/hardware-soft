<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:sans-serif;color:#172033;font-size:9pt}h1{color:#f97316;font-size:18pt}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e5e7eb;padding:2mm;text-align:left}.number{text-align:right}.meta{color:#64748b;margin-bottom:5mm}
</style></head><body>
<h1>HARDEX DEBTOR REPORT</h1>
<div class="meta">{{ $company->company_name }} · {{ $recipient->branch?->name ?: 'Authorized scope' }} · {{ $date->format('d M Y') }}</div>
<table><thead><tr><th>Customer</th><th>Phone</th><th>Reference</th><th>Due Date</th><th>Days Overdue</th><th>Branch</th><th class="number">Outstanding</th></tr></thead><tbody>
@foreach($debts as $sale)
<tr><td>{{ $sale->customer?->name ?: $sale->temporary_customer_name }}</td><td>{{ $sale->customer?->phone ?: $sale->temporary_customer_phone }}</td><td>{{ $sale->sale_number }}</td><td>{{ $sale->expected_payment_date?->format('d M Y') }}</td><td>{{ $sale->expected_payment_date?->isPast() ? $sale->expected_payment_date->startOfDay()->diffInDays($date->copy()->startOfDay()) : 0 }}</td><td>{{ $sale->branch?->name }}</td><td class="number">TZS {{ number_format($sale->balance_amount, 0) }}</td></tr>
@endforeach
</tbody></table></body></html>
