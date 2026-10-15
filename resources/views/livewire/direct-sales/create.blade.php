<?php
use App\Models\{AdditionalChargeType,Branch,Customer,Product,StockLocation}; use App\Services\{B2bQuotationService,InventoryService}; use App\Support\AuthorizationScope; use Illuminate\Support\Str;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['customer_id'=>'','branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),'stock_location_id'=>'','payment_method'=>'credit','notes'=>'','lines'=>[],'additional_charges'=>[],'idempotency_key'=>'']);
mount(function(){ $u=auth()->user();$this->customer_id=(string)request('customer');$this->branch_id=(string)($u->branch_id?:Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->value('id'));$this->idempotency_key=(string)Str::uuid();$this->stock_location_id=(string)StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->where('branch_id',$this->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id'); });
$branches=computed(function(){ $u=auth()->user();$q=Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$u->company_id)->where('status','active');if(AuthorizationScope::scopeFor($u,'sales_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY)$q->whereKey($u->branch_id);return $q->orderBy('name')->get();});
$customers=computed(fn()=>Customer::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$products=computed(fn()=>Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->with(['unit','unitConversions'=>fn($q)=>$q->where('active',true)->where('can_sell',true)->with('unit')])->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get());
$locations=computed(fn()=>StockLocation::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('branch_id',$this->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$lineInfo=computed(function(){ $inventory=app(InventoryService::class);return collect($this->lines)->map(function($line)use($inventory){$p=$this->products->firstWhere('id',(int)($line['product_id']??0));$c=$p?->unitConversions->firstWhere('id',(int)($line['product_unit_conversion_id']??0));$price=filled($line['unit_price']??null)?(float)$line['unit_price']:(float)($c?->retail_price??$p?->selling_price??0);$qty=(float)($line['quantity']??0);$factor=(float)($c?->conversion_factor??1);$base=$qty*$factor;$available=($p&&$this->stock_location_id)?$inventory->getProductStock($p->id,(int)$this->stock_location_id,(int)$this->branch_id):0;return ['base'=>$base,'available'=>$available,'shortage'=>max(0,$base-$available),'total'=>max(0,$qty*($price-(float)($line['discount_per_unit']??0)+(float)($line['tax_amount']??0)))];});});
$total=computed(fn()=>$this->lineInfo->sum('total')+collect($this->additional_charges)->sum(fn($charge)=>(float)($charge['amount']??0)));
$updatedBranchId=function(){ $this->stock_location_id=(string)$this->locations->first()?->id; };
$addLine=function(){ $this->lines[]=['product_id'=>'','product_unit_conversion_id'=>'','quantity'=>1,'unit_price'=>'','discount_per_unit'=>0,'tax_amount'=>0];};$removeLine=function($i){unset($this->lines[$i]);$this->lines=array_values($this->lines);};
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>''];};$removeCharge=function($i){unset($this->additional_charges[$i]);$this->additional_charges=array_values($this->additional_charges);};
$complete=function(B2bQuotationService $s){$this->validate(['customer_id'=>'required|integer','branch_id'=>'required|integer','stock_location_id'=>'required|integer','payment_method'=>'required|in:cash,credit','lines'=>'required|array|min:1','lines.*.product_id'=>'required|integer','lines.*.quantity'=>'required|numeric|gt:0','additional_charges.*.additional_charge_type_id'=>'required|integer','additional_charges.*.amount'=>'required|numeric|gt:0']);$customer=Customer::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',auth()->user()->company_id)->findOrFail($this->customer_id);$sale=$s->createDirectSale($customer,auth()->user(),(int)$this->branch_id,(int)$this->stock_location_id,$this->lines,[['payment_method'=>$this->payment_method,'amount'=>(float)$this->total]],$this->idempotency_key,$this->notes,$this->additional_charges);session()->flash('success',__('feedback.sale_final_invoice_queued', ['number' => $sale->sale_number]));$this->redirectRoute('sales.show',$sale,navigate:true);};
?>
<div>
<x-page-header title="{{ __('application.new_customer_sale_b1d0dee4') }}" description="{{ __('application.complete_a_final_sale_and_invoice_directly_for_an_existing_customer_81253c67') }}" :breadcrumbs="['Sales'=>route('sales.index'),'New Customer Sale'=>null]"/>
<div class="space-y-6">
@can('commercial_charges.apply')
<x-card title="{{ __('application.additional_charges_c6c37e3f') }}"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">{{ __('application.charge_type_316d0d8b') }}<select wire:model.live="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.select_85982229') }}</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="{{ __('application.amount_43dc8532') }}" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model.live="additional_charges.{{ $i }}.amount"/><x-form-input label="{{ __('application.description_optional_388de6fa') }}" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">{{ __('application.add_charge_8fb82fc5') }}</button></x-card>
@endcan
<x-card title="{{ __('application.customer_stock_source_3d1ec92c') }}">
<div class="grid gap-4 md:grid-cols-4">
<label class="text-sm font-bold">{{ __('application.customer_0e85749a') }}<select wire:model="customer_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.select_customer_6817176b') }}</option>@foreach($this->customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->phone }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model.live="branch_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>@foreach($this->branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">{{ __('application.selling_location_49cf3629') }}<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</label>
<label class="text-sm font-bold">{{ __('application.payment_b41a92be') }}<select wire:model="payment_method" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="credit">{{ __('application.credit_outstanding_c575826e') }}</option>
<option value="cash">{{ __('application.cash_paid_42d63749') }}</option>
</select>
</label>
</div>
</x-card>
<x-card title="{{ __('application.products_fe0a091f') }}">
<div class="space-y-3">@foreach($lines as $i=>$line)@php($info=$this->lineInfo[$i]??['base'=>0,'available'=>0,'shortage'=>0])<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-7">
<label class="text-xs font-bold md:col-span-2">{{ __('application.product_dd3b86d1') }}<select wire:model.live="lines.{{ $i }}.product_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.select_85982229') }}</option>@foreach($this->products as $p)<option value="{{ $p->id }}">{{ $p->displayNameWithSize() }}</option>@endforeach</select>
</label>
<label class="text-xs font-bold">{{ __('application.unit_f6b935ab') }}<select wire:model.live="lines.{{ $i }}.product_unit_conversion_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="">{{ __('application.base_077fe9c5') }}</option>@php($p=$this->products->firstWhere('id',(int)$line['product_id']))@foreach($p?->unitConversions??[] as $u)<option value="{{ $u->id }}">{{ $u->unit->name }} × {{ $u->conversion_factor }}</option>@endforeach</select>
</label>
<x-form-input label="{{ __('application.quantity_44f6af69') }}" name="lines.{{ $i }}.quantity" type="number" step="0.0001" wire:model.live="lines.{{ $i }}.quantity"/>
<x-form-input label="{{ __('application.unit_price_1eea68d0') }}" name="lines.{{ $i }}.unit_price" type="number" step="0.01" wire:model.live="lines.{{ $i }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<x-form-input label="{{ __('application.discount_b524936d') }}" name="lines.{{ $i }}.discount_per_unit" type="number" step="0.01" wire:model.live="lines.{{ $i }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
<div class="text-xs">
<p>{{ __('application.base_8d4e397c') }} <b>{{ \App\Support\NumberFormatter::quantity($info['base']) }}</b>
</p>
<p>{{ __('application.available_4a8c2fb2') }} <b>{{ \App\Support\NumberFormatter::quantity($info['available']) }}</b>
</p>
<p class="{{ $info['shortage']>0?'text-red-600 font-black':'' }}">{{ __('application.shortage_77cc1223') }} {{ \App\Support\NumberFormatter::quantity($info['shortage']) }}</p>
<button wire:click="removeLine({{ $i }})" class="mt-2 font-bold text-red-600">{{ __('application.remove_e963907d') }}</button>
</div>
</div>@endforeach</div>
<button wire:click="addLine" class="mt-4 rounded-xl border px-4 py-2 font-black">{{ __('application.add_product_ee611e53') }}</button>@error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror</x-card>
<x-card title="{{ __('application.complete_sale_ebdd2780') }}">
<label class="text-sm font-bold">{{ __('application.notes_70440046') }}<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<div class="mt-4 flex items-center justify-between">
<p class="text-xl font-black">{{ __('application.total_tzs_82632b53') }} {{ \App\Support\NumberFormatter::money($this->total) }}</p>
<button wire:click="complete" wire:confirm="{{ __('application.complete_this_sale_deduct_stock_and_generate_the_final_invoice_a2f79450') }}" class="rounded-xl bg-emerald-600 px-5 py-3 font-black text-white">{{ __('application.complete_sale_generate_invoice_752c74d7') }}</button>
</div>
</x-card>
</div>
</div>
