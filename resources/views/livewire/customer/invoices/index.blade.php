<?php
use App\Models\CompanyPaymentMethod; use App\Models\SalesInvoice; use function Livewire\Volt\computed; use function Livewire\Volt\layout;
layout('layouts.customer');
$invoices=computed(fn()=>SalesInvoice::withoutGlobalScopes()->with('sale.additionalCharges')->where('company_id',auth('customer')->user()->company_id)->where('customer_id',auth('customer')->user()->customer_id)->latest()->paginate(15));
$paymentMethods=computed(fn()=>CompanyPaymentMethod::withoutGlobalScopes()->where('company_id',auth('customer')->user()->company_id)->forDocument('invoice')->get());
?>
<div>
@if($this->paymentMethods->isNotEmpty())<div class="mb-6">
<x-card title="Invoice Payment Instructions">
<p class="mb-3 text-sm text-slate-500">Use the invoice number as your payment reference. Displayed instructions do not record a payment.</p>@foreach($this->paymentMethods as $method)<div class="mb-2 rounded-xl border p-3 last:mb-0 dark:border-slate-700">
<b>{{ $method->display_name }}</b>
<p class="text-sm">{{ collect([$method->provider,$method->bank_name,$method->account_name,$method->account_number,$method->phone_or_business_number,$method->branch_name])->filter()->join(' · ') }}</p>@if($method->instructions)<p class="text-sm text-slate-500">{{ $method->instructions }}</p>@endif</div>@endforeach</x-card>
</div>@endif
<div>
<x-page-header title="My Invoices" description="Download final invoices from quotation conversions and direct staff sales." :breadcrumbs="['Customer Portal'=>route('customer.dashboard'),'Invoices'=>null]"/>
<x-card>
<x-table :headers="['Invoice','Sale','Source','Date','Total','Paid','Balance','']">@forelse($this->invoices as $invoice)<tr>
<td class="px-4 py-3 font-black">{{ $invoice->invoice_number }}</td>
<td class="px-4 py-3">{{ $invoice->sale->sale_number }}</td>
<td class="px-4 py-3">{{ $invoice->source_type==='direct_sale'?'Direct Sale':'Quotation' }}</td>
<td class="px-4 py-3">{{ $invoice->sale->sale_date->format('d M Y') }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->total_amount) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->paid_amount) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->balance_amount) }}</td>
<td class="px-4 py-3">
<a href="{{ route('customer.invoices.pdf',$invoice) }}" class="font-bold text-build-orange">PDF</a>
</td>
</tr>@empty<tr>
<td colspan="8" class="px-4 py-10 text-center text-slate-500">No sales invoices yet.</td>
</tr>@endforelse</x-table>
<div class="mt-4">{{ $this->invoices->links() }}</div>
</x-card>
</div>
</div>
