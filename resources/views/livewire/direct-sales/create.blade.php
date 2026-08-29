<?php
use App\Models\{AdditionalChargeType,Branch,Customer,Product,StockLocation}; use App\Services\{B2bQuotationService,InventoryService}; use App\Support\AuthorizationScope; use Illuminate\Support\Str;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['customer_id'=>'','branch_id'=>'','stock_location_id'=>'','payment_method'=>'credit','notes'=>'','lines'=>[],'additional_charges'=>[],'idempotency_key'=>'']);
mount(function(){ $u=auth()->user();$this->customer_id=(string)request('customer');$this->branch_id=(string)($u->branch_id?:Branch::withoutGlobalScopes()->where('company_id',$u->company_id)->value('id'));$this->idempotency_key=(string)Str::uuid();$this->stock_location_id=(string)StockLocation::withoutGlobalScopes()->where('company_id',$u->company_id)->where('branch_id',$this->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id'); });
$branches=computed(function(){ $u=auth()->user();$q=Branch::withoutGlobalScopes()->where('company_id',$u->company_id)->where('status','active');if(AuthorizationScope::scopeFor($u,'sales_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY)$q->whereKey($u->branch_id);return $q->orderBy('name')->get();});
$customers=computed(fn()=>Customer::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$products=computed(fn()=>Product::withoutGlobalScopes()->with(['unit','unitConversions'=>fn($q)=>$q->where('active',true)->where('can_sell',true)->with('unit')])->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$locations=computed(fn()=>StockLocation::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('branch_id',$this->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$lineInfo=computed(function(){ $inventory=app(InventoryService::class);return collect($this->lines)->map(function($line)use($inventory){$p=$this->products->firstWhere('id',(int)($line['product_id']??0));$c=$p?->unitConversions->firstWhere('id',(int)($line['product_unit_conversion_id']??0));$price=filled($line['unit_price']??null)?(float)$line['unit_price']:(float)($c?->retail_price??$p?->selling_price??0);$qty=(float)($line['quantity']??0);$factor=(float)($c?->conversion_factor??1);$base=$qty*$factor;$available=($p&&$this->stock_location_id)?$inventory->getProductStock($p->id,(int)$this->stock_location_id,(int)$this->branch_id):0;return ['base'=>$base,'available'=>$available,'shortage'=>max(0,$base-$available),'total'=>max(0,$qty*($price-(float)($line['discount_per_unit']??0)+(float)($line['tax_amount']??0)))];});});
$total=computed(fn()=>$this->lineInfo->sum('total')+collect($this->additional_charges)->sum(fn($charge)=>(float)($charge['amount']??0)));
$updatedBranchId=function(){ $this->stock_location_id=(string)$this->locations->first()?->id; };
$addLine=function(){ $this->lines[]=['product_id'=>'','product_unit_conversion_id'=>'','quantity'=>1,'unit_price'=>'','discount_per_unit'=>0,'tax_amount'=>0];};$removeLine=function($i){unset($this->lines[$i]);$this->lines=array_values($this->lines);};
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>''];};$removeCharge=function($i){unset($this->additional_charges[$i]);$this->additional_charges=array_values($this->additional_charges);};
$complete=function(B2bQuotationService $s){$this->validate(['customer_id'=>'required|integer','branch_id'=>'required|integer','stock_location_id'=>'required|integer','payment_method'=>'required|in:cash,credit','lines'=>'required|array|min:1','lines.*.product_id'=>'required|integer','lines.*.quantity'=>'required|numeric|gt:0','additional_charges.*.additional_charge_type_id'=>'required|integer','additional_charges.*.amount'=>'required|numeric|gt:0']);$customer=Customer::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->findOrFail($this->customer_id);$sale=$s->createDirectSale($customer,auth()->user(),(int)$this->branch_id,(int)$this->stock_location_id,$this->lines,[['payment_method'=>$this->payment_method,'amount'=>(float)$this->total]],$this->idempotency_key,$this->notes,$this->additional_charges);session()->flash('success',"Sale {$sale->sale_number} completed and final invoice queued.");$this->redirectRoute('sales.show',$sale,navigate:true);};
?>
<div>
<x-page-header title="New Customer Sale" description="Complete a final sale and invoice directly for an existing customer." :breadcrumbs="['Sales'=>route('sales.index'),'New Customer Sale'=>null]"/>
<div class="space-y-6">
@can('commercial_charges.apply')
<x-card title="Additional Charges"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">Charge Type<select wire:model.live="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">Select</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="Amount" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model.live="additional_charges.{{ $i }}.amount"/><x-form-input label="Description (optional)" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">Remove</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">Add Charge</button></x-card>
@endcan
<x-card title="Customer & Stock Source">
<div class="grid gap-4 md:grid-cols-4">
<label class="text-sm font-bold">Customer<select wire:model="customer_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Select customer</option>@foreach($this->customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->phone }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">Branch<select wire:model.live="branch_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">Selling Location<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">Payment<select wire:model="payment_method" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="credit">Credit / Outstanding</option>
<option value="cash">Cash Paid</option>
</select>
</label>
</div>
</x-card>
<x-card title="Products">
<div class="space-y-3">@foreach($lines as $i=>$line)@php($info=$this->lineInfo[$i]??['base'=>0,'available'=>0,'shortage'=>0])<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-7">
<label class="text-xs font-bold md:col-span-2">Product<select wire:model.live="lines.{{ $i }}.product_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Select</option>@foreach($this->products as $p)<option value="{{ $p->id }}">{{ $p->displayNameWithSize() }}</option>@endforeach</select>
</label>
<label class="text-xs font-bold">Unit<select wire:model.live="lines.{{ $i }}.product_unit_conversion_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">Base</option>@php($p=$this->products->firstWhere('id',(int)$line['product_id']))@foreach($p?->unitConversions??[] as $u)<option value="{{ $u->id }}">{{ $u->unit->name }} × {{ $u->conversion_factor }}</option>@endforeach</select>
</label>
<x-form-input label="Quantity" name="lines.{{ $i }}.quantity" type="number" step="0.0001" wire:model.live="lines.{{ $i }}.quantity"/>
<x-form-input label="Unit Price" name="lines.{{ $i }}.unit_price" type="number" step="0.01" wire:model.live="lines.{{ $i }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<x-form-input label="Discount" name="lines.{{ $i }}.discount_per_unit" type="number" step="0.01" wire:model.live="lines.{{ $i }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
<div class="text-xs">
<p>Base: <b>{{ \App\Support\NumberFormatter::quantity($info['base']) }}</b>
</p>
<p>Available: <b>{{ \App\Support\NumberFormatter::quantity($info['available']) }}</b>
</p>
<p class="{{ $info['shortage']>0?'text-red-600 font-black':'' }}">Shortage: {{ \App\Support\NumberFormatter::quantity($info['shortage']) }}</p>
<button wire:click="removeLine({{ $i }})" class="mt-2 font-bold text-red-600">Remove</button>
</div>
</div>@endforeach</div>
<button wire:click="addLine" class="mt-4 rounded-xl border px-4 py-2 font-black">Add Product</button>@error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror</x-card>
<x-card title="Complete Sale">
<label class="text-sm font-bold">Notes<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<div class="mt-4 flex items-center justify-between">
<p class="text-xl font-black">Total: TZS {{ \App\Support\NumberFormatter::money($this->total) }}</p>
<button wire:click="complete" wire:confirm="Complete this sale, deduct stock, and generate the final invoice?" class="rounded-xl bg-emerald-600 px-5 py-3 font-black text-white">Complete Sale & Generate Invoice</button>
</div>
</x-card>
</div>
</div>
