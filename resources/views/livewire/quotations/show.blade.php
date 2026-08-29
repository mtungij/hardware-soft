<?php
use App\Models\{Quotation,StockLocation}; use App\Services\B2bQuotationService; use App\Support\AuthorizationScope; use Illuminate\Auth\Access\AuthorizationException;
use function Livewire\Volt\{computed,layout,mount,state};
layout('layouts.app'); state(['quotation'=>null,'stock_location_id'=>'','payment_method'=>'credit','acceptance_reason'=>'']);
mount(function(Quotation $quotation){ $u=auth()->user(); if((int)$quotation->company_id!==(int)$u->company_id||(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY&&(int)$quotation->branch_id!==(int)$u->branch_id))throw new AuthorizationException; $this->quotation=$quotation->load(['items','additionalCharges','customer','branch','purchaseRequest','convertedSale']); $this->stock_location_id=(string)StockLocation::withoutGlobalScopes()->where('company_id',$u->company_id)->where('branch_id',$quotation->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id'); });
$locations=computed(fn()=>StockLocation::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('branch_id',$this->quotation->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$availability=computed(fn()=>!$this->stock_location_id?[]:app(B2bQuotationService::class)->availability($this->quotation,(int)$this->stock_location_id));
$canConvert=computed(function(){ $availability=collect($this->availability); return $availability->count()===$this->quotation->items->count()&&$availability->isNotEmpty()&&$availability->every(fn(array $row)=>(float)$row['shortage']<=0); });
$send=function(B2bQuotationService $s){$this->quotation=$s->send($this->quotation,auth()->user())->load(['items','customer','branch','purchaseRequest','convertedSale']);session()->flash('success','Document PDF queued for WhatsApp delivery.');};
$acceptOffline=function(B2bQuotationService $s){$this->validate(['acceptance_reason'=>'required|string|min:3|max:500']);$this->quotation=$s->markAcceptedOffline($this->quotation,auth()->user(),$this->acceptance_reason)->load(['items','customer','branch','purchaseRequest','convertedSale']);session()->flash('success','Customer acceptance recorded.');};
$convert=function(B2bQuotationService $s){$this->validate(['stock_location_id'=>'required|integer','payment_method'=>'required|in:cash,credit']);$amount=(float)$this->quotation->total_amount;$sale=$s->convertToSale($this->quotation,auth()->user(),(int)$this->stock_location_id,[['payment_method'=>$this->payment_method,'amount'=>$amount]]);session()->flash('success',"Sale {$sale->sale_number} completed and final invoice queued.");$this->redirectRoute('sales.show',$sale,navigate:true);};
?>
<div>
@if($quotation->additionalCharges->isNotEmpty())<div class="mb-6">
<x-card title="Additional Charges">@foreach($quotation->additionalCharges as $charge)<div class="flex justify-between border-b py-2 last:border-0">
<span>
<b>{{ $charge->charge_name_snapshot }}</b>@if($charge->description_snapshot)<small class="block text-slate-500">{{ $charge->description_snapshot }}</small>@endif</span>
<b>TZS {{ \App\Support\NumberFormatter::money($charge->amount) }}</b>
</div>@endforeach</x-card>
</div>@endif
<div>
<x-page-header :title="$quotation->quotation_number" :description="str($quotation->document_type)->headline().' · '.str($quotation->status)->headline()" :breadcrumbs="['Quotations'=>route('quotations.index'),$quotation->quotation_number=>null]">
<a href="{{ route('quotations.pdf',$quotation) }}" class="rounded-xl border px-4 py-2 font-black">Download PDF</a>
</x-page-header>
<div class="grid gap-6 xl:grid-cols-3">
<div class="space-y-6 xl:col-span-2">
<x-card title="Document">
<div class="grid gap-3 sm:grid-cols-3">
<div>
<p class="text-xs text-slate-500">Customer</p>
<p class="font-black">{{ $quotation->customer->name }}</p>
</div>
<div>
<p class="text-xs text-slate-500">Source</p>
<p class="font-black">{{ $quotation->source_type==='staff_created'?'Created by Staff':'Customer Request' }}</p>
</div>
<div>
<p class="text-xs text-slate-500">Valid Until</p>
<p class="font-black">{{ $quotation->valid_until->format('d M Y') }}</p>
</div>
</div>
<div class="mt-4">
<x-table :headers="['Product','Unit','Qty','Base Qty','Price','Discount','Total']">@foreach($quotation->items as $item)<tr>
<td class="px-4 py-3 font-bold">{{ $item->product_name_snapshot }}</td>
<td class="px-4 py-3">{{ $item->transaction_unit_name_snapshot }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::money($item->discount_amount) }}</td>
<td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
</tr>@endforeach</x-table>
</div>
<p class="mt-4 text-right text-xl font-black">Total: TZS {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</p>
</x-card>
<x-card title="Stock Availability">
<x-table :headers="['Product','Required (base)','Available','Shortage']">@foreach($this->availability as $row)<tr>
<td class="px-4 py-3 font-bold">{{ $row['product'] }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['required']) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['available']) }}</td>
<td class="px-4 py-3 {{ $row['shortage']>0?'text-red-600 font-black':'' }}">{{ \App\Support\NumberFormatter::quantity($row['shortage']) }}</td>
</tr>@endforeach</x-table>
</x-card>
</div>
<div class="space-y-6">
<x-card title="Actions">@if(in_array($quotation->status,['draft','sent'])&&auth()->user()->can('quotations.send'))<button wire:click="send" class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ $quotation->status==='sent'?'Resend':'Send' }} via WhatsApp</button>@endif @if($quotation->status==='sent'&&auth()->user()->can('quotations.convert'))<label class="mt-4 block text-sm font-bold">Offline acceptance evidence<textarea wire:model="acceptance_reason" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950" placeholder="e.g. Customer confirmed by phone">
</textarea>
</label>
<button wire:click="acceptOffline" class="mt-3 w-full rounded-xl border border-emerald-500 px-4 py-3 font-black text-emerald-700">Mark Accepted</button>@endif @if($quotation->status==='accepted'&&auth()->user()->can($quotation->customer_purchase_request_id?'customer_requests.convert_to_sale':'quotations.convert'))<label class="mt-4 block text-sm font-bold">Stock Location<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</label>
<label class="mt-3 block text-sm font-bold">Payment<select wire:model="payment_method" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="credit">Credit / Outstanding</option>
<option value="cash">Cash Paid</option>
</select>
</label>@if(!$this->canConvert)<div data-testid="conversion-stock-warning" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">Cannot convert this quotation to a sale because the selected stock location has insufficient stock. Replenish stock or select another eligible stock location.</div>@endif @error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror<button data-testid="convert-final-sale" wire:click="convert" wire:confirm="Complete this sale and deduct stock?" @disabled(!$this->canConvert) class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">Convert to Final Sale</button>@endif @if($quotation->convertedSale)<a href="{{ route('sales.show',$quotation->convertedSale) }}" wire:navigate class="mt-4 block text-center font-black text-build-orange">View Sale {{ $quotation->convertedSale->sale_number }}</a>@endif</x-card>
<x-card title="Selling Location">
<select wire:model.live="stock_location_id" class="w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
</x-card>
</div>
</div>
</div>
</div>
