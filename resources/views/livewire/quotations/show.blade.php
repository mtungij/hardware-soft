<?php
use App\Models\{Quotation,StockLocation}; use App\Services\B2bQuotationService; use App\Support\AuthorizationScope; use Illuminate\Auth\Access\AuthorizationException;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['quotation'=>null,'stock_location_id'=>'','on_credit'=>true,'payment_method'=>\App\Services\PaymentMethodService::defaultValue(),'acceptance_reason'=>'']);
mount(function(Quotation $quotation){ $u=auth()->user(); if((int)$quotation->company_id!==(int)$u->company_id||(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY&&(int)$quotation->branch_id!==(int)$u->branch_id))throw new AuthorizationException; $this->quotation=$quotation->load(['items','additionalCharges','customer','branch','purchaseRequest','convertedSale']); $this->stock_location_id=(string)StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->where('branch_id',$quotation->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id'); });
$locations=computed(fn()=>StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('branch_id',$this->quotation->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$availability=computed(fn()=>!$this->stock_location_id?[]:app(B2bQuotationService::class)->availability($this->quotation,(int)$this->stock_location_id));
$canConvert=computed(function(){ $availability=collect($this->availability); return $availability->count()===$this->quotation->items->count()&&$availability->isNotEmpty()&&$availability->every(fn(array $row)=>(float)$row['shortage']<=0); });
$send=function(B2bQuotationService $s){$this->quotation=$s->send($this->quotation,auth()->user())->load(['items','customer','branch','purchaseRequest','convertedSale']);session()->flash('success',__('application.document_pdf_queued_for_whatsapp_delivery_abfbd3d9'));};
$acceptOffline=function(B2bQuotationService $s){$this->validate(['acceptance_reason'=>'required|string|min:3|max:500']);$this->quotation=$s->markAcceptedOffline($this->quotation,auth()->user(),$this->acceptance_reason)->load(['items','customer','branch','purchaseRequest','convertedSale']);session()->flash('success',__('application.customer_acceptance_recorded_1daab9b2'));};
$convert=function(B2bQuotationService $s){$this->validate(['on_credit'=>['required','boolean']]);if($this->on_credit){$this->payment_method='credit';}$this->validate(['stock_location_id'=>'required|integer','payment_method'=>['required', new \App\Rules\AvailablePaymentMethod(true)]]);$amount=(float)$this->quotation->total_amount;$sale=$s->convertToSale($this->quotation,auth()->user(),(int)$this->stock_location_id,[['payment_method'=>($this->on_credit?'credit':$this->payment_method),'amount'=>$amount]]);session()->flash('success',__('feedback.sale_final_invoice_queued', ['number' => $sale->sale_number]));$this->redirectRoute('sales.show',$sale,navigate:true);};
$updatedPaymentMethod = function () { $this->on_credit = $this->payment_method === 'credit'; };
$updatedOnCredit = function () { if (! $this->on_credit) { $this->payment_method = \App\Services\PaymentMethodService::defaultValue(); } };
?>
<div>
@if($quotation->additionalCharges->isNotEmpty())<div class="mb-6">
<x-card title="{{ __('application.additional_charges_c6c37e3f') }}">@foreach($quotation->additionalCharges as $charge)<div class="flex justify-between border-b py-2 last:border-0">
<span>
<b>{{ $charge->charge_name_snapshot }}</b>@if($charge->description_snapshot)<small class="block text-slate-500">{{ $charge->description_snapshot }}</small>@endif</span>
<b>TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</b>
</div>@endforeach</x-card>
</div>@endif
<div>
<x-page-header :title="$quotation->quotation_number"  :translate-title="false" :description="\App\Support\UiText::label($quotation->document_type).' · '.\App\Support\UiText::label($quotation->status)" :breadcrumbs="['Quotations'=>route('quotations.index'),$quotation->quotation_number=>null]">
@if ($quotation->document_type === 'quotation')
<span class="text-sm font-bold">{{ __('application.template_7d0db7be') }} {{ \App\Support\QuotationTemplateRegistry::saved($quotation->quotation_template_key)['name'] }}</span>
<a href="{{ route('quotations.preview', $quotation) }}" target="_blank" rel="noopener" class="rounded-xl border px-4 py-2 font-black">{{ __('application.preview_f1fbb2b4') }}</a>
@endif
<a href="{{ route('quotations.pdf',$quotation) }}" class="rounded-xl border px-4 py-2 font-black">{{ __('application.download_pdf_98e5ef06') }}</a>
</x-page-header>
<div class="grid gap-6 xl:grid-cols-3">
<div class="space-y-6 xl:col-span-2">
<x-card title="{{ __('application.document_e214b8a2') }}">
<div class="grid gap-3 sm:grid-cols-3">
<div>
<p class="text-xs text-slate-500">{{ __('application.customer_0e85749a') }}</p>
<p class="font-black">{{ $quotation->customer->displayName() }}</p>
</div>
<div>
<p class="text-xs text-slate-500">{{ __('application.source_6da13add') }}</p>
<p class="font-black">{{ $quotation->source_type==='staff_created'?__('application.created_by_staff_481df1bb'):__('application.customer_request_98f75b67') }}</p>
</div>
<div>
<p class="text-xs text-slate-500">{{ __('application.valid_until_a144230d') }}</p>
<p class="font-black">{{ $quotation->valid_until->locale(app()->getLocale())->translatedFormat('d M Y') }}</p>
</div>
</div>
<div class="mt-4">
<x-table :headers="[__('application.product_dd3b86d1'),__('application.unit_f6b935ab'),__('application.qty_1e5ff9e5'),__('application.base_qty_ec4bdf4d'),__('application.price_3e8248e3'),__('application.discount_b524936d'),__('application.total_b25928c6')]">@foreach($quotation->items as $item)<tr>
<td class="px-4 py-3 font-bold">{{ $item->product_name_snapshot }}</td>
<td class="px-4 py-3">{{ $item->transaction_unit_name_snapshot }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->discount_amount) }}</td>
<td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
</tr>@endforeach</x-table>
</div>
<p class="mt-4 text-right text-xl font-black">{{ __('application.total_tzs_82632b53') }} {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</p>
</x-card>
<x-card title="{{ __('application.stock_availability_8f757bd6') }}">
<x-table :headers="[__('application.product_dd3b86d1'),__('terminology.required_base_f39dfe20'),__('application.available_7c62a142'),__('application.shortage_7ce60ebb')]">@foreach($this->availability as $row)<tr>
<td class="px-4 py-3 font-bold">{{ $row['product'] }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['required']) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['available']) }}</td>
<td class="px-4 py-3 {{ $row['shortage']>0?'text-red-600 font-black':'' }}">{{ \App\Support\NumberFormatter::quantity($row['shortage']) }}</td>
</tr>@endforeach</x-table>
</x-card>
</div>
<div class="space-y-6">
<x-card title="{{ __('application.actions_c3cd636a') }}">@if(in_array($quotation->status,['draft','sent'])&&auth()->user()->can('quotations.send'))<button wire:click="send" class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ $quotation->status==='sent'?__('terminology.resend_0f4f4694'):__('application.send_9bc2575c') }} {{ __('application.via_whatsapp_bd4dac24') }}</button>@endif @if($quotation->status==='sent'&&auth()->user()->can('quotations.convert'))<label class="mt-4 block text-sm font-bold">{{ __('application.offline_acceptance_evidence_87933d3b') }}<textarea wire:model="acceptance_reason" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950" placeholder="{{ __('application.e_g_customer_confirmed_by_phone_a3c1d2c6') }}">
</textarea>
</label>
<button wire:click="acceptOffline" class="mt-3 w-full rounded-xl border border-emerald-500 px-4 py-3 font-black text-emerald-700">{{ __('application.mark_accepted_9af04218') }}</button>@endif @if($quotation->status==='accepted'&&auth()->user()->can($quotation->customer_purchase_request_id?'customer_requests.convert_to_sale':'quotations.convert'))<label class="mt-4 block text-sm font-bold">{{ __('application.stock_location_50e3c4fe') }}<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</label>
<label class="mt-3 block text-sm font-bold">{{ __('application.payment_b41a92be') }}<span class="mb-2 block"><label><input type="checkbox" wire:model.live="on_credit"> {{ __('payments.credit') }}</label></span>
<select wire:model="payment_method" @disabled($on_credit) class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><x-payment-method-options :branch-id="$branch_id ?? null"   /></select>
</label>@if(!$this->canConvert)<div data-testid="conversion-stock-warning" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">{{ __('application.cannot_convert_this_quotation_to_a_sale_because_the_selected_stock_location_9ab64cc5') }}</div>@endif @error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror<button data-testid="convert-final-sale" wire:click="convert" wire:confirm="{{ __('application.complete_this_sale_and_deduct_stock_4a654fe8') }}" @disabled(!$this->canConvert) class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">{{ __('application.convert_to_final_sale_569553bb') }}</button>@endif @if($quotation->convertedSale)<a href="{{ route('sales.show',$quotation->convertedSale) }}" wire:navigate class="mt-4 block text-center font-black text-build-orange">{{ __('application.view_sale_bd1f1469') }} {{ $quotation->convertedSale->sale_number }}</a>@endif</x-card>
<x-card title="{{ __('application.selling_location_49cf3629') }}">
<select wire:model.live="stock_location_id" class="w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</x-card>
</div>
</div>
</div>
</div>
