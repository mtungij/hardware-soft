<?php

use App\Models\Branch;
use App\Models\Product;
use App\Services\CustomerPurchaseRequestService;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['search' => '', 'branch_id' => '', 'notes' => '', 'items' => [], 'submission_key' => '']);

mount(function (): void {
    $account = auth('customer')->user();
    $this->branch_id = (string) ($account->customer?->branch_id ?: Branch::withoutGlobalScopes()->where('company_id', $account->company_id)->where('status', 'active')->orderByDesc('is_default')->value('id'));
    $this->submission_key = (string) str()->uuid();
});

$products = computed(fn () => Product::withoutGlobalScopes()->with(['unit', 'unitConversions' => fn ($query) => $query->with('unit')->where('active', true)->where('can_sell', true)])
    ->where('company_id', auth('customer')->user()->company_id)->where('status', 'active')
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%")))
    ->orderBy('name')->limit(30)->get());

$branches = computed(fn () => Branch::withoutGlobalScopes()->where('company_id', auth('customer')->user()->company_id)->where('status', 'active')->orderBy('name')->get());

$addProduct = function (int $productId): void {
    if (collect($this->items)->contains(fn ($item) => (int) $item['product_id'] === $productId)) return;
    $product = Product::withoutGlobalScopes()->where('company_id', auth('customer')->user()->company_id)->where('status', 'active')->findOrFail($productId);
    $this->items[] = ['product_id' => $product->id, 'product_name' => $product->displayNameWithSize(), 'product_unit_conversion_id' => '', 'quantity' => 1, 'notes' => ''];
};

$removeItem = function (int $index): void { unset($this->items[$index]); $this->items = array_values($this->items); };

$submit = function (CustomerPurchaseRequestService $service): void {
    $this->validate([
        'branch_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:2000'],
        'submission_key' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'integer'], 'items.*.product_unit_conversion_id' => ['nullable', 'integer'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.notes' => ['nullable', 'string', 'max:500'],
    ]);
    $request = $service->submit(auth('customer')->user(), (int) $this->branch_id, $this->notes, $this->items, $this->submission_key);
    session()->flash('success', "Purchase request {$request->request_number} submitted.");
    $this->redirectRoute('customer.purchase-requests.show', $request, navigate: true);
};
?>

<div>
    <x-page-header title="New Purchase Request" description="Select products and transaction units. Stock is not reserved until staff converts an accepted quotation to a sale." :breadcrumbs="['My Purchase Requests'=>route('customer.purchase-requests.index'),'New Request'=>null]" />
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Products">
            <input wire:model.live.debounce.300ms="search" placeholder="Search product or SKU" class="mb-4 w-full rounded-xl border-slate-200 dark:bg-navy-950">
            <div class="max-h-[34rem] space-y-2 overflow-y-auto">@foreach($this->products as $product)<div class="flex items-center gap-3 rounded-xl border p-3 dark:border-slate-700">
                <img src="{{ $product->image_url }}" class="h-12 w-12 rounded-lg object-cover" alt="">
                <div class="min-w-0 flex-1"><p class="font-black">{{ $product->displayNameWithSize() }}</p><p class="text-xs text-slate-500">{{ $product->sku ?: 'No SKU' }} · Base: {{ $product->unit?->short_name }}</p></div>
                <button type="button" wire:click="addProduct({{ $product->id }})" class="rounded-lg bg-build-orange px-3 py-2 text-xs font-black text-white">Add</button>
            </div>@endforeach</div>
        </x-card>
        <form wire:submit="submit" class="space-y-4"><x-card title="Request Details">
            <label class="block text-sm font-bold">Preferred Branch<select wire:model="branch_id" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">@foreach($this->branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <div class="mt-4 space-y-3">@forelse($items as $index=>$row)@php($product=$this->products->firstWhere('id',(int)$row['product_id']) ?: \App\Models\Product::withoutGlobalScopes()->with(['unit','unitConversions.unit'])->find($row['product_id']))<div class="rounded-xl border p-3 dark:border-slate-700" wire:key="request-item-{{ $index }}">
                <div class="flex justify-between"><b>{{ $row['product_name'] }}</b><button type="button" wire:click="removeItem({{ $index }})" class="text-xs font-bold text-red-600">Remove</button></div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs font-bold">Unit<select wire:model="items.{{ $index }}.product_unit_conversion_id" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ $product?->unit?->short_name }} (Base)</option>@foreach($product?->unitConversions?->where('active',true)->where('can_sell',true) ?? [] as $conversion)<option value="{{ $conversion->id }}">{{ $conversion->unit?->short_name }} (×{{ \App\Support\NumberFormatter::quantity($conversion->conversion_factor) }})</option>@endforeach</select></label><x-form-input label="Quantity" name="items.{{ $index }}.quantity" type="number" step="0.0001" wire:model="items.{{ $index }}.quantity" /></div>
                <x-form-input label="Item Notes" name="items.{{ $index }}.notes" wire:model="items.{{ $index }}.notes" />
            </div>@empty<p class="py-8 text-center text-sm text-slate-500">Add products from the list.</p>@endforelse</div>
            <label class="mt-4 block text-sm font-bold">Request Notes<textarea wire:model="notes" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950" rows="3"></textarea></label>
            @error('items')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <button class="mt-4 w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white" wire:loading.attr="disabled">Submit Purchase Request</button>
        </x-card></form>
    </div>
</div>
