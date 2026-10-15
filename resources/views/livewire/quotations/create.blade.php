<?php
use App\Models\{AdditionalChargeType,Branch,Customer,Product}; use App\Services\B2bQuotationService; use App\Support\AuthorizationScope; use Illuminate\Support\Str;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['customer_id'=>'','branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),'document_type'=>'quotation','quotation_template_key'=>'','valid_until'=>'','notes'=>'','terms'=> __('application.payment_and_delivery_subject_to_agreed_terms_16b31c17'),'lines'=>[],'additional_charges'=>[],'creation_key'=>'']);
mount(function(){ $u=auth()->user(); $this->document_type=in_array(request('type'),['quotation','proforma'])?request('type'):'quotation'; $this->customer_id=(string)request('customer'); $this->branch_id=(string)($u->branch_id?:Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->value('id')); $this->valid_until=today()->addDays(14)->toDateString(); $this->creation_key=(string)Str::uuid(); });
$branches=computed(function(){ $u=auth()->user(); $q=Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->where('status','active'); if(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY)$q->whereKey($u->branch_id); return $q->orderBy('name')->get(); });
$customers=computed(fn()=>Customer::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$products=computed(fn()=>Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->with(['unit','unitConversions'=>fn($q)=>$q->where('active',true)->where('can_sell',true)->with('unit')])->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$addLine=function(){ $this->lines[]=['product_id'=>'','product_unit_conversion_id'=>'','quantity'=>1,'unit_price'=>'','discount_per_unit'=>0,'tax_amount'=>0]; };
$removeLine=function($i){ unset($this->lines[$i]); $this->lines=array_values($this->lines); };
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>'']; };
$removeCharge=function($i){ unset($this->additional_charges[$i]); $this->additional_charges=array_values($this->additional_charges); };
$save=function(B2bQuotationService $service){ $this->validate(['customer_id'=>'required|integer','branch_id'=>'required|integer','document_type'=>'required|in:quotation,proforma','valid_until'=>'required|date','lines'=>'required|array|min:1','lines.*.product_id'=>'required|integer','lines.*.quantity'=>'required|numeric|gt:0','additional_charges.*.additional_charge_type_id'=>'required|integer','additional_charges.*.amount'=>'required|numeric|gt:0']); $customer=Customer::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->findOrFail($this->customer_id); $q=$service->createDirect($customer,auth()->user(),(int)$this->branch_id,$this->lines,$this->document_type,$this->valid_until,$this->creation_key,$this->notes,$this->terms,$this->additional_charges,$this->quotation_template_key); session()->flash('success',"{$q->quotation_number} created."); $this->redirectRoute('quotations.show',$q,navigate:true); };
?>
<div>
<x-page-header :title="$document_type==='proforma'?__('terminology.new_proforma_invoice_5a701702'):__('application.new_quotation_cb9d9eb8')" description="{{ __('application.prepare_a_document_directly_for_an_existing_customer_2c177d00') }}" :breadcrumbs="['Quotations'=>route('quotations.index'),'Create'=>null]"/>
<div class="space-y-6">
@can('commercial_charges.apply')
<x-card title="{{ __('application.additional_charges_c6c37e3f') }}"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">{{ __('application.charge_type_316d0d8b') }}<select wire:model="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.select_85982229') }}</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="{{ __('application.amount_43dc8532') }}" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model="additional_charges.{{ $i }}.amount"/><x-form-input label="{{ __('application.description_optional_388de6fa') }}" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">{{ __('application.add_charge_8fb82fc5') }}</button></x-card>
@endcan
<x-card title="{{ __('application.customer_document_85f14564') }}">
<div class="grid gap-4 md:grid-cols-3">
<label class="text-sm font-bold">{{ __('application.customer_0e85749a') }}<select wire:model="customer_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.select_customer_6817176b') }}</option>@foreach($this->customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->phone }}</option>@endforeach</select>@error('customer_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
<label class="text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model="branch_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>@foreach($this->branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
</label>
@include('documents.quotations.partials.selector')
<x-form-input label="{{ __('application.valid_until_a144230d') }}" name="valid_until" type="date" wire:model="valid_until"/>
</div>
</x-card>
<x-card title="{{ __('application.products_fe0a091f') }}">
<div class="space-y-3">@foreach($lines as $i=>$line)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-6">
<label class="text-xs font-bold md:col-span-2">{{ __('application.product_dd3b86d1') }}<select wire:model="lines.{{ $i }}.product_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.select_85982229') }}</option>@foreach($this->products as $p)<option value="{{ $p->id }}">{{ $p->displayNameWithSize() }} ({{ $p->unit?->name }})</option>@endforeach</select>
</label>
<label class="text-xs font-bold">{{ __('application.selling_unit_293a9282') }}<select wire:model="lines.{{ $i }}.product_unit_conversion_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.base_unit_e1532ca3') }}</option>@php($selected=$this->products->firstWhere('id',(int)$line['product_id']))@foreach($selected?->unitConversions??[] as $u)<option value="{{ $u->id }}">{{ $u->unit->name }} × {{ $u->conversion_factor }}</option>@endforeach</select>
</label>
<x-form-input label="{{ __('application.quantity_44f6af69') }}" name="lines.{{ $i }}.quantity" type="number" step="0.0001" wire:model="lines.{{ $i }}.quantity"/>
<x-form-input label="{{ __('application.unit_price_1eea68d0') }}" name="lines.{{ $i }}.unit_price" type="number" step="0.01" wire:model="lines.{{ $i }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<div>
<x-form-input label="{{ __('application.discount_unit_404cfa57') }}" name="lines.{{ $i }}.discount_per_unit" type="number" step="0.01" wire:model="lines.{{ $i }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
<button wire:click="removeLine({{ $i }})" class="mt-1 text-xs font-bold text-red-600">{{ __('application.remove_e963907d') }}</button>
</div>
</div>@endforeach</div>
<button wire:click="addLine" class="mt-4 rounded-xl border px-4 py-2 font-black">{{ __('application.add_product_ee611e53') }}</button>@error('lines')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</x-card>
<x-card title="{{ __('application.commercial_notes_f6e63211') }}">
<div class="grid gap-4 md:grid-cols-2">
<label class="text-sm font-bold">{{ __('application.notes_70440046') }}<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<label class="text-sm font-bold">{{ __('application.terms_a55a275a') }}<textarea wire:model="terms" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
</div>
<button wire:click="save" class="mt-4 rounded-xl bg-build-orange px-5 py-3 font-black text-white">{{ __('application.create_6e157c5d') }} {{ \App\Support\UiText::label($document_type) }}</button>
</x-card>
</div>
</div>
