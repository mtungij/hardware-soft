<?php
use App\Models\{AdditionalChargeType,Branch,Customer,Product}; use App\Services\B2bQuotationService; use App\Support\AuthorizationScope; use Illuminate\Support\Str;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['customer_id'=>'','branch_id'=>'','document_type'=>'quotation','valid_until'=>'','notes'=>'','terms'=>'Payment and delivery subject to agreed terms.','lines'=>[],'additional_charges'=>[],'creation_key'=>'']);
mount(function(){ $u=auth()->user(); $this->document_type=in_array(request('type'),['quotation','proforma'])?request('type'):'quotation'; $this->customer_id=(string)request('customer'); $this->branch_id=(string)($u->branch_id?:Branch::withoutGlobalScopes()->where('company_id',$u->company_id)->value('id')); $this->valid_until=today()->addDays(14)->toDateString(); $this->creation_key=(string)Str::uuid(); });
$branches=computed(function(){ $u=auth()->user(); $q=Branch::withoutGlobalScopes()->where('company_id',$u->company_id)->where('status','active'); if(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY)$q->whereKey($u->branch_id); return $q->orderBy('name')->get(); });
$customers=computed(fn()=>Customer::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$products=computed(fn()=>Product::withoutGlobalScopes()->with(['unit','unitConversions'=>fn($q)=>$q->where('active',true)->where('can_sell',true)->with('unit')])->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$addLine=function(){ $this->lines[]=['product_id'=>'','product_unit_conversion_id'=>'','quantity'=>1,'unit_price'=>'','discount_per_unit'=>0,'tax_amount'=>0]; };
$removeLine=function($i){ unset($this->lines[$i]); $this->lines=array_values($this->lines); };
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>'']; };
$removeCharge=function($i){ unset($this->additional_charges[$i]); $this->additional_charges=array_values($this->additional_charges); };
$save=function(B2bQuotationService $service){ $this->validate(['customer_id'=>'required|integer','branch_id'=>'required|integer','document_type'=>'required|in:quotation,proforma','valid_until'=>'required|date','lines'=>'required|array|min:1','lines.*.product_id'=>'required|integer','lines.*.quantity'=>'required|numeric|gt:0','additional_charges.*.additional_charge_type_id'=>'required|integer','additional_charges.*.amount'=>'required|numeric|gt:0']); $customer=Customer::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->findOrFail($this->customer_id); $q=$service->createDirect($customer,auth()->user(),(int)$this->branch_id,$this->lines,$this->document_type,$this->valid_until,$this->creation_key,$this->notes,$this->terms,$this->additional_charges); session()->flash('success',"{$q->quotation_number} created."); $this->redirectRoute('quotations.show',$q,navigate:true); };
?>
<div>
<x-page-header :title="$document_type==='proforma'?'New Proforma Invoice':'New Quotation'" description="Prepare a document directly for an existing customer." :breadcrumbs="['Quotations'=>route('quotations.index'),'Create'=>null]"/>
<div class="space-y-6">
@can('commercial_charges.apply')
<x-card title="Additional Charges"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">Charge Type<select wire:model="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">Select</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="Amount" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model="additional_charges.{{ $i }}.amount"/><x-form-input label="Description (optional)" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">Remove</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">Add Charge</button></x-card>
@endcan
<x-card title="Customer & Document">
<div class="grid gap-4 md:grid-cols-3">
<label class="text-sm font-bold">Customer<select wire:model="customer_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Select customer</option>@foreach($this->customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->phone }}</option>@endforeach</select>@error('customer_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
<label class="text-sm font-bold">Branch<select wire:model="branch_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
</label>
<x-form-input label="Valid Until" name="valid_until" type="date" wire:model="valid_until"/>
</div>
</x-card>
<x-card title="Products">
<div class="space-y-3">@foreach($lines as $i=>$line)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-6">
<label class="text-xs font-bold md:col-span-2">Product<select wire:model="lines.{{ $i }}.product_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Select</option>@foreach($this->products as $p)<option value="{{ $p->id }}">{{ $p->displayNameWithSize() }} ({{ $p->unit?->name }})</option>@endforeach</select>
</label>
<label class="text-xs font-bold">Selling Unit<select wire:model="lines.{{ $i }}.product_unit_conversion_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Base unit</option>@php($selected=$this->products->firstWhere('id',(int)$line['product_id']))@foreach($selected?->unitConversions??[] as $u)<option value="{{ $u->id }}">{{ $u->unit->name }} × {{ $u->conversion_factor }}</option>@endforeach</select>
</label>
<x-form-input label="Quantity" name="lines.{{ $i }}.quantity" type="number" step="0.0001" wire:model="lines.{{ $i }}.quantity"/>
<x-form-input label="Unit Price" name="lines.{{ $i }}.unit_price" type="number" step="0.01" wire:model="lines.{{ $i }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<div>
<x-form-input label="Discount / Unit" name="lines.{{ $i }}.discount_per_unit" type="number" step="0.01" wire:model="lines.{{ $i }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
<button wire:click="removeLine({{ $i }})" class="mt-1 text-xs font-bold text-red-600">Remove</button>
</div>
</div>@endforeach</div>
<button wire:click="addLine" class="mt-4 rounded-xl border px-4 py-2 font-black">Add Product</button>@error('lines')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</x-card>
<x-card title="Commercial Notes">
<div class="grid gap-4 md:grid-cols-2">
<label class="text-sm font-bold">Notes<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<label class="text-sm font-bold">Terms<textarea wire:model="terms" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
</div>
<button wire:click="save" class="mt-4 rounded-xl bg-build-orange px-5 py-3 font-black text-white">Create {{ str($document_type)->headline() }}</button>
</x-card>
</div>
</div>
