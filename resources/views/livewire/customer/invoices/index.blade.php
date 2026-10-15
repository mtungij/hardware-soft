<?php
use App\Models\CompanyPaymentMethod; use App\Models\SalesInvoice; use function Livewire\Volt\computed; use function Livewire\Volt\layout;
layout('layouts.customer');
$invoices=computed(fn()=>SalesInvoice::withoutGlobalScopes()->with('sale.additionalCharges')->where('company_id',auth('customer')->user()->company_id)->where('customer_id',auth('customer')->user()->customer_id)->latest()->paginate(15));
$paymentMethods=computed(fn()=>CompanyPaymentMethod::withoutGlobalScopes()->where('company_id',auth('customer')->user()->company_id)->forDocument('invoice')->get());
?>
<div>
@if($this->paymentMethods->isNotEmpty())<div class="mb-6">
<x-card title="{{ __('application.invoice_payment_instructions_9b4f8e5f') }}">
<p class="mb-3 text-sm text-slate-500">{{ __('application.use_the_invoice_number_as_your_payment_reference_displayed_instructions_do__a7c0a42d') }}</p>@foreach($this->paymentMethods as $method)<div class="mb-2 rounded-xl border p-3 last:mb-0 dark:border-slate-700">
<b>{{ $method->display_name }}</b>
<p class="text-sm">{{ collect([$method->provider,$method->bank_name,$method->account_name,$method->account_number,$method->phone_or_business_number,$method->branch_name])->filter()->join(' · ') }}</p>@if($method->instructions)<p class="text-sm text-slate-500">{{ $method->instructions }}</p>@endif</div>@endforeach</x-card>
</div>@endif
<div>
<x-page-header title="{{ __('application.my_invoices_ed85bbb1') }}" description="{{ __('application.download_final_invoices_from_quotation_conversions_and_direct_staff_sales_80efc2fd') }}" :breadcrumbs="['Customer Portal'=>route('customer.dashboard'),'Invoices'=>null]"/>
<x-card>
<x-table :headers="[__('application.invoice_f9f38818'),__('application.sale_0028d743'),__('application.source_6da13add'),__('application.date_eb9a4bc1'),__('application.total_b25928c6'),__('application.paid_dc9d4584'),__('application.balance_90eef613'),'']">@forelse($this->invoices as $invoice)<tr>
<td class="px-4 py-3 font-black">{{ $invoice->invoice_number }}</td>
<td class="px-4 py-3">{{ $invoice->sale->sale_number }}</td>
<td class="px-4 py-3">{{ $invoice->source_type==='direct_sale'?__('terminology.direct_sale_63b8393f'):__('application.quotation_5a232fc6') }}</td>
<td class="px-4 py-3">{{ $invoice->sale->sale_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->total_amount) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->paid_amount) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($invoice->sale->balance_amount) }}</td>
<td class="px-4 py-3">
<a href="{{ route('customer.invoices.pdf',$invoice) }}" class="font-bold text-build-orange">PDF</a>
</td>
</tr>@empty<tr>
<td colspan="8" class="px-4 py-10 text-center text-slate-500">{{ __('application.no_sales_invoices_yet_5a7bf3d2') }}</td>
</tr>@endforelse</x-table>
<div class="mt-4">{{ $this->invoices->links() }}</div>
</x-card>
</div>
</div>
