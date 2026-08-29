<?php
use App\Models\AdditionalChargeType; use App\Models\CustomerPurchaseRequest; use App\Models\StockLocation; use App\Services\B2bQuotationService; use App\Services\CustomerPurchaseRequestService; use App\Services\InventoryService; use App\Support\AuthorizationScope; use Illuminate\Auth\Access\AuthorizationException;
use function Livewire\Volt\computed; use function Livewire\Volt\layout; use function Livewire\Volt\mount; use function Livewire\Volt\state;
layout('layouts.app');
state(['purchaseRequest'=>null,'quotation'=>null,'quote_lines'=>[],'additional_charges'=>[],'document_type'=>'quotation','valid_until'=>'','notes'=>'','terms'=>'Payment and delivery subject to agreed terms.','stock_location_id'=>'','payment_method'=>'credit']);
mount(function(CustomerPurchaseRequest $customerPurchaseRequest){$u=auth()->user();if((int)$customerPurchaseRequest->company_id!==(int)$u->company_id||(AuthorizationScope::scopeFor($u,'report_scope',AuthorizationScope::BRANCH)!==AuthorizationScope::COMPANY&&(int)$customerPurchaseRequest->branch_id!==(int)$u->branch_id))throw new AuthorizationException;$this->purchaseRequest=$customerPurchaseRequest->load(['customer','branch','items','quotations.items']);$this->quotation=$this->purchaseRequest->quotations->sortByDesc('id')->first();$this->valid_until=today()->addDays(14)->toDateString();$this->stock_location_id=(string)StockLocation::withoutGlobalScopes()->where('company_id',$u->company_id)->where('branch_id',$customerPurchaseRequest->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->value('id');foreach($this->purchaseRequest->items as $item)$this->quote_lines[]=['request_item_id'=>$item->id,'quantity'=>(float)$item->transaction_quantity,'unit_price'=>(float)$item->display_unit_price_snapshot,'discount_per_unit'=>0,'tax_amount'=>0];});
$locations=computed(fn()=>StockLocation::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('branch_id',$this->purchaseRequest->branch_id)->where('status','active')->where('is_active',true)->where('can_sell',true)->get());
$chargeTypes=computed(fn()=>AdditionalChargeType::withoutGlobalScopes()->where('company_id',auth()->user()->company_id)->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get());
$availability=computed(function(){if(!$this->stock_location_id)return collect();$inventory=app(InventoryService::class);return $this->purchaseRequest->items->map(fn($item)=>['product'=>$item->product_name_snapshot,'requested'=>(float)$item->base_quantity,'available'=>$inventory->getProductStock($item->product_id,(int)$this->stock_location_id,$this->purchaseRequest->branch_id)]);});
$beginReview=function(CustomerPurchaseRequestService $service){$this->purchaseRequest=$service->beginReview($this->purchaseRequest,auth()->user());session()->flash('success','Request marked under review.');};
$addCharge=function(){ $this->additional_charges[]=['additional_charge_type_id'=>'','amount'=>'','description'=>''];};$removeCharge=function($i){unset($this->additional_charges[$i]);$this->additional_charges=array_values($this->additional_charges);};
$createQuotation=function(B2bQuotationService $service){$this->validate(['document_type'=>['required','in:quotation,proforma'],'valid_until'=>['required','date'],'quote_lines'=>['required','array','min:1'],'quote_lines.*.quantity'=>['required','numeric','gt:0'],'quote_lines.*.unit_price'=>['required','numeric','min:0'],'quote_lines.*.discount_per_unit'=>['required','numeric','min:0'],'additional_charges.*.additional_charge_type_id'=>['required','integer'],'additional_charges.*.amount'=>['required','numeric','gt:0']]);$this->quotation=$service->createFromRequest($this->purchaseRequest,auth()->user(),$this->quote_lines,$this->document_type,$this->valid_until,$this->notes,$this->terms,$this->additional_charges);session()->flash('success',"{$this->quotation->quotation_number} created.");};
$sendQuotation=function(B2bQuotationService $service){$this->quotation=$service->send($this->quotation,auth()->user());session()->flash('success','Quotation PDF queued to the customer through WhatsApp.');};
$convert=function(B2bQuotationService $service){$this->validate(['stock_location_id'=>['required','integer'],'payment_method'=>['required','in:cash,credit']]);$amount=(float)$this->quotation->total_amount;$sale=$service->convertToSale($this->quotation,auth()->user(),(int)$this->stock_location_id,[['payment_method'=>$this->payment_method,'amount'=>$amount]]);session()->flash('success',"Sale {$sale->sale_number} completed and invoice queued.");$this->redirectRoute('sales.show',$sale,navigate:true);};
?>
<div>
<x-page-header :title="$purchaseRequest->request_number" description="Review request, current availability, quotation, and conversion." :breadcrumbs="['Customer Requests'=>route('customer-requests.index'),$purchaseRequest->request_number=>null]">@if($purchaseRequest->status==='pending'&&auth()->user()->can('customer_requests.review'))<button wire:click="beginReview" class="rounded-xl border px-4 py-2 text-sm font-black">Begin Review</button>@endif</x-page-header>
<div class="grid gap-6 xl:grid-cols-3">
<div class="space-y-6 xl:col-span-2">
@if(!$quotation) @can('commercial_charges.apply')<x-card title="Additional Charges"><div class="space-y-3">@foreach($additional_charges as $i=>$charge)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 md:grid-cols-4"><label class="text-xs font-bold">Charge Type<select wire:model="additional_charges.{{ $i }}.additional_charge_type_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950"><option value="">Select</option>@foreach($this->chargeTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label><x-form-input label="Amount" name="additional_charges.{{ $i }}.amount" type="number" step="0.01" wire:model="additional_charges.{{ $i }}.amount"/><x-form-input label="Description (optional)" name="additional_charges.{{ $i }}.description" wire:model="additional_charges.{{ $i }}.description"/><button wire:click="removeCharge({{ $i }})" class="self-end rounded-xl border border-red-200 px-3 py-2 text-sm font-bold text-red-600">Remove</button></div>@endforeach</div><button wire:click="addCharge" class="mt-4 rounded-xl border px-4 py-2 font-black">Add Charge</button></x-card>@endcan @endif
<x-card title="Customer Request">
<div class="mb-4">
<b>{{ $purchaseRequest->customer->name }}</b> · {{ $purchaseRequest->branch->name }}<p class="text-sm text-slate-500">{{ $purchaseRequest->customer_notes }}</p>
</div>
<x-table :headers="['Product','Unit','Requested','Base Qty','Available','Shortage']">@foreach($purchaseRequest->items as $index=>$item)@php($stock=$this->availability[$index]??null)<tr>
<td class="px-4 py-3 font-bold">{{ $item->product_name_snapshot }}</td>
<td class="px-4 py-3">{{ $item->transaction_unit_name_snapshot }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->transaction_quantity) }}</td>
<td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_quantity) }}</td>
<td class="px-4 py-3">{{ $stock ? \App\Support\NumberFormatter::quantity($stock['available']) : '-' }}</td>
<td class="px-4 py-3">{{ $stock ? \App\Support\NumberFormatter::quantity(max(0,$stock['requested']-$stock['available'])) : '-' }}</td>
</tr>@endforeach</x-table>
</x-card>
@if(!$quotation)<x-card title="Prepare Quotation">
<div class="grid gap-3 sm:grid-cols-2">
<label class="text-sm font-bold">Document Type<select wire:model="document_type" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="quotation">Quotation</option>
<option value="proforma">Proforma Invoice</option>
</select>
</label>
<x-form-input label="Valid Until" name="valid_until" type="date" wire:model="valid_until" />
</div>
<div class="mt-4 space-y-3">@foreach($quote_lines as $index=>$line)<div class="grid gap-3 rounded-xl border p-3 dark:border-slate-700 sm:grid-cols-3">
<div>
<b>{{ $purchaseRequest->items[$index]->product_name_snapshot }}</b>
<p class="text-xs text-slate-500">{{ $purchaseRequest->items[$index]->transaction_unit_name_snapshot }}</p>
</div>
<x-form-input label="Fulfillable Qty" name="quote_lines.{{ $index }}.quantity" type="number" step="0.0001" wire:model="quote_lines.{{ $index }}.quantity"/>
<x-form-input label="Unit Price" name="quote_lines.{{ $index }}.unit_price" type="number" step="0.01" wire:model="quote_lines.{{ $index }}.unit_price" :disabled="!auth()->user()->can('products.edit_selling_price')"/>
<x-form-input label="Discount / Unit" name="quote_lines.{{ $index }}.discount_per_unit" type="number" step="0.01" wire:model="quote_lines.{{ $index }}.discount_per_unit" :disabled="!auth()->user()->can('sales.discount')"/>
</div>@endforeach</div>
<label class="mt-4 block text-sm font-bold">Customer Notes<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<label class="mt-3 block text-sm font-bold">Terms<textarea wire:model="terms" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
</textarea>
</label>
<button wire:click="createQuotation" class="mt-4 rounded-xl bg-build-orange px-4 py-3 font-black text-white">Create Quotation</button>
</x-card>@else<x-card title="Quotation">
<div class="flex flex-wrap items-center justify-between gap-3">
<div>
<p class="text-xl font-black">{{ $quotation->quotation_number }}</p>
<p>{{ str($quotation->status)->headline() }} · TZS {{ \App\Support\NumberFormatter::money($quotation->total_amount) }}</p>
</div>
<a href="{{ route('quotations.pdf',$quotation) }}" class="rounded-xl border px-4 py-2 font-bold">PDF</a>
</div>@if($quotation->status==='draft'&&auth()->user()->can('quotations.send'))<button wire:click="sendQuotation" class="mt-4 rounded-xl bg-build-orange px-4 py-3 font-black text-white">Send PDF via WhatsApp</button>@endif</x-card>@endif</div>
<div class="space-y-6">
<x-card title="Stock Source">
<label class="text-sm font-bold">Selling Location<select wire:model.live="stock_location_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
</label>
</x-card>@if($quotation?->status==='accepted'&&auth()->user()->can('customer_requests.convert_to_sale'))<x-card title="Convert to Sale">
<label class="text-sm font-bold">Payment<select wire:model="payment_method" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
<option value="credit">Credit / Outstanding</option>
<option value="cash">Cash Paid</option>
</select>
</label>@error('stock')<div class="mt-3 whitespace-pre-line rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</div>@enderror<button wire:click="convert" wire:confirm="Complete this accepted quotation as a sale? Stock will be deducted." class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white">Complete Sale</button>
</x-card>@endif<x-card title="Audit Status">
<p class="font-black">{{ str($purchaseRequest->status)->headline() }}</p>
<p class="mt-2 text-sm text-slate-500">Submitted {{ $purchaseRequest->submitted_at->format('d M Y H:i') }}</p>
</x-card>
</div>
</div>
</div>
