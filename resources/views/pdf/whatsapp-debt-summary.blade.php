<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:sans-serif;color:#172033;font-size:9pt}h1{color:#f97316;font-size:18pt}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e5e7eb;padding:2mm;text-align:left}.number{text-align:right}.meta{color:#64748b;margin-bottom:5mm}
</style></head><body>
<h1>{{ __('application.hardex_debtor_report_ac535961') }}</h1>
<div class="meta">{{ $company->company_name }} · {{ $recipient->branch?->name ?: __('application.authorized_scope_f42a290b') }} · {{ $date->locale(app()->getLocale())->translatedFormat('d M Y') }}</div>
<table><thead><tr><th>{{ __('application.customer_0e85749a') }}</th><th>{{ __('application.phone_77064d52') }}</th><th>{{ __('application.reference_db1c7845') }}</th><th>{{ __('application.due_date_a1b308ec') }}</th><th>{{ __('application.days_overdue_3f5eea24') }}</th><th>{{ __('application.branch_1627510b') }}</th><th class="number">{{ __('application.outstanding_f8ee57ec') }}</th></tr></thead><tbody>
@foreach($debts as $sale)
<tr><td>{{ $sale->customer?->displayName() ?: $sale->temporary_customer_name }}</td><td>{{ $sale->customer?->phone ?: $sale->temporary_customer_phone }}</td><td>{{ $sale->sale_number }}</td><td>{{ $sale->expected_payment_date?->locale(app()->getLocale())->translatedFormat('d M Y') }}</td><td>{{ $sale->expected_payment_date?->isPast() ? $sale->expected_payment_date->startOfDay()->diffInDays($date->copy()->startOfDay()) : 0 }}</td><td>{{ $sale->branch?->name }}</td><td class="number">TZS {{ number_format($sale->balance_amount, 0) }}</td></tr>
@endforeach
</tbody></table></body></html>
