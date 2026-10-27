<?php
use App\Models\AdditionalChargeType; use App\Models\CustomerPurchaseRequest; use App\Models\StockLocation; use App\Services\B2bQuotationService; use App\Services\CustomerPurchaseRequestService; use App\Services\InventoryService; use App\Support\AuthorizationScope; use Illuminate\Auth\Access\AuthorizationException;
use function Livewire\Volt\computed; use function Livewire\Volt\layout; use function Livewire\Volt\mount; use function Livewire\Volt\state;
layout('layouts.app');
state(['purchaseRequest'=>null,'quotation'=>null,'quote_lines'=>[],'additional_charges'=>[],'document_type'=>'quotation','quotation_template_key'=>'','valid_until'=>'','notes'=>'','terms'=> __('application.payment_and_delivery_subject_to_agreed_terms_16b31c17'),'stock_location_id'=>'','on_credit'=>true,'payment_method'=>\App\Services\PaymentMethodService::defaultValue()]);
mount(function(CustomerPurchaseRequest $customerPurchaseRequest){$u=auth()->user();if((int)$customerPurchaseRequest->company_id!==(int)$u->company_id||(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY&&(int)$customerPurchaseRequest->branch_id!==(int)$u->branch_id))throw new AuthorizationException;$this->purchaseRequest=$customerPurchaseRequest->load(['customer','branch','items','quotations.items']);$this->quotation=$this->purchaseRequest->quotations->sortByDesc('id')->first();$this->valid_until=today()->addDays(14)->toDateString();$this->stock_location_id=(string)StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->where('branch_id',$customerPurchaseRequest->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id');foreach($this->purchaseRequest->items as $item)$this->quote_lines[]=['request_item_id'=>$item->id,'quantity'=>(float)$item->transaction_quantity,'unit_price'=>(float)$item->display_unit_price_snapshot,'discount_per_unit'=>0,'tax_amount'=>0];});
$locations=computed(fn()=>StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('branch_id',$this->purchaseRequest->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$availability=computed(function(){if(!$this->stock_location_id)return collect();$inventory=app(InventoryService::class);return $this->purchaseRequest->items->map(fn($item)=>['product'=>$item->product_name_snapshot,'requested'=>(float)$item->base_quantity,'available'=>$inventory->getProductStock($item->product_id,(int)$this->stock_location_id,$this->purchaseRequest->branch_id)]);});
$beginReview=function(CustomerPurchaseRequestService $service){$this->purchaseRequest=$service->beginReview($this->purchaseRequest,auth()->user());session()->flash('success',__('application.request_marked_under_review_678dcb56'));};
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>''];};$removeCharge=function($i){unset($this->additional_charges[$i]);$this->additional_charges=array_values($this->additional_charges);};
$createQuotation=function(B2bQuotationService $service){$this->validate(['document_type'=>['required','in:quotation,proforma'],'valid_until'=>['required','date'],'quote_lines'=>['required','array','min:1'],'quote_lines.*.quantity'=>['required','numeric','gt:0'],'quote_lines.*.unit_price'=>['required','numeric','min:0'],'quote_lines.*.discount_per_unit'=>['required','numeric','min:0'],'additional_charges.*.additional_charge_type_id'=>['required','integer'],'additional_charges.*.amount'=>['required','numeric','gt:0']]);$this->quotation=$service->createFromRequest($this->purchaseRequest,auth()->user(),$this->quote_lines,$this->document_type,$this->valid_until,$this->notes,$this->terms,$this->additional_charges,$this->quotation_template_key);session()->flash('success',__('feedback.document_created', ['number' => $this->quotation->quotation_number]));};
$sendQuotation=function(B2bQuotationService $service){$this->quotation=$service->send($this->quotation,auth()->user());session()->flash('success',__('application.quotation_pdf_queued_to_the_customer_through_whatsapp_289339c6'));};
$convert=function(B2bQuotationService $service){$this->validate(['on_credit'=>['required','boolean']]);if($this->on_credit){$this->payment_method='credit';}$this->validate(['stock_location_id'=>['required','integer'],'payment_method'=>['required',new \App\Rules\AvailablePaymentMethod(true)]]);$amount=(float)$this->quotation->total_amount;$sale=$service->convertToSale($this->quotation,auth()->user(),(int)$this->stock_location_id,[['payment_method'=>($this->on_credit?'credit':$this->payment_method),'amount'=>$amount]]);session()->flash('success',__('feedback.sale_invoice_queued', ['number' => $sale->sale_number]));$this->redirectRoute('sales.show',$sale,navigate:true);};
$rejectRequest = function (CustomerPurchaseRequestService $service): void {
    $this->validate(['notes' => ['nullable', 'string', 'max:2000']]);
    $this->purchaseRequest = $service->reject($this->purchaseRequest, auth()->user(), $this->notes);
};
$updatedPaymentMethod = function () { $this->on_credit = $this->payment_method === 'credit'; };
$updatedOnCredit = function () { if (! $this->on_credit) { $this->payment_method = \App\Services\PaymentMethodService::defaultValue(); } };
?>
<div>@if(in_array($purchaseRequest->status, ['pending','under_review']) && auth()->user()->can('customer_requests.review'))
<button wire:click="rejectRequest" wire:confirm="{{ __('portal.reject_confirm') }}" class="mb-4 min-h-12 rounded-xl border border-red-300 px-4 py-3 font-bold text-red-600">{{ __('portal.reject_request') }}</button>
@endif<section class="mb-5 rounded-xl border p-4"><h2 class="font-black">{{ __('portal.request_details') }}</h2><p>{{ __('portal.'.($purchaseRequest->request_type === 'order' ? 'order' : 'rfq')) }}</p>@if($purchaseRequest->delivery_requested)<p class="mt-2 font-bold">{{ __('portal.delivery_address') }}</p><p>{{ $purchaseRequest->delivery_address }}</p><p>{{ $purchaseRequest->delivery_instructions }}</p>@endif</section>
<x-page-header :title="$purchaseRequest->request_number"  :translate-title="false" description="{{ __('application.review_request_current_availability_quotation_and_conversion_3539bb7e') }}" :breadcrumbs="['Customer Requests'=>route('customer-requests.index'),$purchaseRequest->request_number=>null]">@if($purchaseRequest->status==='pending'&&auth()->user()->can('customer_requests.review'))<button wire:click="beginReview" class="rounded-xl border px-4 py-2 text-sm font-black">{{ __('application.begin_review_5ec1e1ca') }}</button>@endif</x-page-header>
<div class="grid gap-6 xl:grid-cols-3">
<div class="space-y-6 xl:col-span-2">
@if(!$quotation) @can('commercial_charges.apply')<x-card title="{{ __('application.additional_charges_c6c37e3f') }}"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">{{ __('application.charge_type_316d0d8b') }}<select wire:model="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.select_85982229') }}</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="{{ __('application.amount_43dc8532') }}" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model="additional_charges.{{ $i }}.amount"/><x-form-input label="{{ __('application.description_optional_388de6fa') }}" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">{{ __('application.add_charge_8fb82fc5') }}</button></x-card>@endcan @endif
<x-card title="{{ __('application.customer_request_98f75b67') }}">
<div class="mb-4">
<b>{{ $purchaseRequest->customer->displayName() }}</b> · {{ $purchaseRequest->branch->name }}<p class="text-sm text-slate-500">{{ $purchaseRequest->customer_notes }}</p>
</div>
<x-table :headers="[__('application.product_dd3b86d1'),__('application.unit_f6b935ab'),__('terminology.requested_c26bf60f'),__('application.base_qty_ec4bdf4d'),__('application.available_7c62a142'),__('application.shortage_7ce60ebb')]">@foreach($purchaseRequest->items as $index=>$item)@php($stock=$this->availability[$index]??null)<tr>
<td class="px-4 py-3 font-bold">{{ $item->product_name_snapshot }}</td>
<td class="px-4 py-3">{{ $item->transaction_unit_name_snapshot }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_quantity) }}</td>
<td class="px-4 py-3">{{ $stock ? \App\Support\NumberFormatter::quantity($stock['available']) : '-' }}</td>
<td class="px-4 py-3">{{ $stock ? \App\Support\NumberFormatter::quantity(max(0,$stock['requested']-$stock['available'])) : '-' }}</td>
</tr>@endforeach</x-table>
</x-card>
@if(!$quotation)<x-card title="{{ __('application.prepare_quotation_7f9ef84b') }}">
<div class="grid gap-3 sm:grid-cols-2">
<label class="text-sm font-bold">{{ __('application.document_type_2c21f688') }}<select wire:model.live="document_type" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="quotation">{{ __('application.quotation_5a232fc6') }}</option>
<option value="proforma">{{ __('application.proforma_invoice_4c8d3cf3') }}</option>
</select>
</label>
@include('documents.quotations.partials.selector')
<x-form-input label="{{ __('application.valid_until_a144230d') }}" name="valid_until" type="date" wire:model="valid_until" />
</div>
<div class="mt-4 space-y-3">@foreach($quote_lines as $index=>$line)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 sm:grid-cols-3">
<div>
<b>{{ $purchaseRequest->items[$index]->product_name_snapshot }}</b>
<p class="text-xs text-slate-500">{{ $purchaseRequest->items[$index]->transaction_unit_name_snapshot }}</p>
</div>
<x-form-input label="{{ __('application.fulfillable_qty_ac93d0f8') }}" name="quote_lines.{{ $index }}.quantity" type="number" step="0.0001" wire:model="quote_lines.{{ $index }}.quantity"/>
<x-form-input label="{{ __('application.unit_price_1eea68d0') }}" name="quote_lines.{{ $index }}.unit_price" type="number" step="0.01" wire:model="quote_lines.{{ $index }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<x-form-input label="{{ __('application.discount_unit_404cfa57') }}" name="quote_lines.{{ $index }}.discount_per_unit" type="number" step="0.01" wire:model="quote_lines.{{ $index }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
</div>@endforeach</div>
<label class="mt-4 block text-sm font-bold">{{ __('application.customer_notes_b108ce6b') }}<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<label class="mt-3 block text-sm font-bold">{{ __('application.terms_a55a275a') }}<textarea wire:model="terms" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<button wire:click="createQuotation" class="mt-4 rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ __('application.create_quotation_37ef31c2') }}</button>
</x-card>@else<x-card title="{{ __('application.quotation_5a232fc6') }}">
<div class="flex flex-wrap items-center justify-between gap-3">
<div>
<p class="text-xl font-black">{{ $quotation->quotation_number }}</p>
<p>{{ \App\Support\UiText::label($quotation->status) }} · TZS {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</p>
</div>
<a href="{{ route('quotations.pdf',$quotation) }}" class="rounded-xl border px-4 py-2 font-bold">PDF</a>
</div>@if($quotation->status==='draft'&&auth()->user()->can('quotations.send'))<button wire:click="sendQuotation" class="mt-4 rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ __('application.send_pdf_via_whatsapp_b8315197') }}</button>@endif</x-card>@endif</div>
<div class="space-y-6">
<x-card title="{{ __('application.stock_source_29f5a910') }}">
<label class="text-sm font-bold">{{ __('application.selling_location_49cf3629') }}<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
</label>
</x-card>@if($quotation?->status==='accepted'&&auth()->user()->can('customer_requests.convert_to_sale'))<x-card title="{{ __('application.convert_to_sale_4fddc777') }}">
<label class="text-sm font-bold">{{ __('application.payment_b41a92be') }}<span class="mb-2 block"><label><input type="checkbox" wire:model.live="on_credit"> {{ __('payments.credit') }}</label></span>
<select wire:model="payment_method" @disabled($on_credit) class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><x-payment-method-options :branch-id="$branch_id ?? null"   /></select>
</label>@error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror<button wire:click="convert" wire:confirm="{{ __('application.complete_this_accepted_quotation_as_a_sale_stock_will_be_deducted_18571a2f') }}" class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white">{{ __('application.complete_sale_ebdd2780') }}</button>
</x-card>@endif<x-card title="{{ __('application.audit_status_49298247') }}">
<p class="font-black">{{ \App\Support\UiText::label($purchaseRequest->status) }}</p>
<p class="mt-2 text-sm text-slate-500">{{ __('application.submitted_2e00359b') }} {{ $purchaseRequest->submitted_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</p>
</x-card>
</div>
</div>
</div>
