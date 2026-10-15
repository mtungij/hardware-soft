<?php
use App\Models\CompanyPaymentMethod; use App\Models\Quotation; use App\Services\B2bQuotationService; use Illuminate\Auth\Access\AuthorizationException;
use function Livewire\Volt\computed; use function Livewire\Volt\layout; use function Livewire\Volt\mount; use function Livewire\Volt\state;
layout('layouts.customer'); state(['quotation'=>null,'rejection_reason'=>'']);
mount(function(Quotation $quotation){$a=auth('customer')->user();if((int)$quotation->company_id!==(int)$a->company_id||(int)$quotation->customer_id!==(int)$a->customer_id)throw new AuthorizationException;$this->quotation=$quotation->load(['items','additionalCharges','purchaseRequest']);});
$paymentMethods=computed(fn()=>CompanyPaymentMethod::withoutGlobalScopes()->where('company_id',$this->quotation->company_id)->forDocument($this->quotation->document_type)->get());
$accept=function(B2bQuotationService $service){$this->quotation=$service->accept($this->quotation,auth('customer')->user());session()->flash('success',__('application.quotation_accepted_hardex_staff_have_been_notified_97f97f1c'));};
$reject=function(B2bQuotationService $service){$this->validate(['rejection_reason'=>['nullable','string','max:1000']]);$this->quotation=$service->reject($this->quotation,auth('customer')->user(),$this->rejection_reason);session()->flash('success',__('application.quotation_rejected_5e792463'));};
?>
<div>
@if($quotation->additionalCharges->isNotEmpty() || $this->paymentMethods->isNotEmpty())<div class="mb-6 grid gap-6 lg:grid-cols-2">@if($quotation->additionalCharges->isNotEmpty())<x-card title="{{ __('application.additional_charges_c6c37e3f') }}">@foreach($quotation->additionalCharges as $charge)<div class="flex justify-between border-b py-2 last:border-0">
<span>
<b>{{ $charge->charge_name_snapshot }}</b>@if($charge->description_snapshot)<small class="block text-slate-500">{{ $charge->description_snapshot }}</small>@endif</span>
<b>TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</b>
</div>@endforeach</x-card>@endif @if($this->paymentMethods->isNotEmpty())<x-card title="{{ __('application.payment_instructions_f3fa021f') }}">
<p class="mb-3 text-sm text-slate-500">{{ __('application.use_payment_reference_680bfdef') }} <b>{{ $quotation->quotation_number }}</b>{{ __('application.these_instructions_do_not_record_a_payment_90155fbb') }}</p>@foreach($this->paymentMethods as $method)<div class="mb-3 rounded-xl border p-3 last:mb-0 dark:border-slate-700">
<b>{{ $method->display_name }}</b>
<p class="text-sm">{{ collect([$method->provider,$method->bank_name,$method->account_name,$method->account_number,$method->phone_or_business_number,$method->branch_name])->filter()->join(' · ') }}</p>@if($method->instructions)<p class="mt-1 text-sm text-slate-500">{{ $method->instructions }}</p>@endif</div>@endforeach</x-card>@endif</div>@endif
<div>
<x-page-header :title="$quotation->quotation_number"  :translate-title="false" :description="\App\Support\UiText::label($quotation->document_type)" :breadcrumbs="['My Quotations'=>route('customer.quotations.index'),$quotation->quotation_number=>null]">
<a href="{{ route('customer.quotations.pdf',$quotation) }}" class="rounded-xl border px-4 py-2 text-sm font-black">{{ __('application.download_pdf_98e5ef06') }}</a>
</x-page-header>
<div class="grid gap-6 lg:grid-cols-3">
<x-card class="lg:col-span-2" title="{{ __('application.quotation_items_a7e78dfb') }}">
<x-table :headers="[__('application.product_dd3b86d1'),__('application.unit_f6b935ab'),__('application.quantity_44f6af69'),__('application.unit_price_1eea68d0'),__('application.discount_b524936d'),__('application.total_b25928c6')]">@foreach($quotation->items as $item)<tr>
<td class="px-4 py-3 font-bold">{{ $item->product_name_snapshot }}</td>
<td class="px-4 py-3">{{ $item->transaction_unit_name_snapshot }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->discount_amount) }}</td>
<td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
</tr>@endforeach</x-table>
</x-card>
<x-card title="{{ __('application.decision_7f59a1f1') }}">
<p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</p>
<p class="mt-2 text-sm">{{ __('application.status_11dc9e19') }} <b>{{ \App\Support\UiText::label($quotation->status) }}</b>
</p>
<p class="text-sm text-slate-500">{{ __('application.valid_until_c8214d35') }} {{ $quotation->valid_until->locale(app()->getLocale())->translatedFormat('d M Y') }}</p>@if($quotation->status==='sent'&&!$quotation->valid_until->isBefore(today()))<button wire:click="accept" wire:confirm="{{ __('application.accept_this_quotation_8e1b92ea') }}" class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white">{{ __('application.accept_quotation_ddbb32f9') }}</button>
<textarea wire:model="rejection_reason" rows="3" placeholder="{{ __('application.optional_rejection_reason_43afd22e') }}" class="mt-3 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
<button wire:click="reject" wire:confirm="{{ __('application.reject_this_quotation_831519d7') }}" class="mt-2 w-full rounded-xl border border-red-300 px-4 py-3 font-black text-red-600">{{ __('application.reject_quotation_ac2ba4a0') }}</button>@endif</x-card>
</div>
</div>
</div>
