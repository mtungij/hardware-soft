<?php

use App\Models\Product;
use App\Models\ProductLocationSetting;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\StockLocation;
use App\Services\GoodsReceiptCostingService;
use App\Services\InventoryService;
use App\Services\PurchaseCostBreakdownService;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['purchase' => null, 'purchase_id' => null])->locked();

state([
    'grn_number' => '',
    'received_date' => '',
    'supplier_delivery_note_number' => '',
    'supplier_invoice_number' => '',
    'default_stock_location_id' => '',
    'notes' => '',
    'lines' => [],
    'additional_costs' => [],
    'new_cost_type' => '',
]);

mount(function (Purchase $purchase, InventoryService $inventory) {
    abort_if($purchase->status === 'cancelled' || $purchase->status === 'received', 403);

    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes((int) $purchase->company_id);
    $this->purchase = $purchase->load(['supplier', 'branch', 'creator', 'items.product', 'items.purchaseUnit.measurementType', 'items.stockUnit']);
    $this->purchase_id = $purchase->id;
    if (blank($this->grn_number)) {
        $this->grn_number = $inventory->generateGrnNumber((int) $purchase->company_id);
    }
    $this->received_date = now()->toDateString();

    $locations = $this->availableReceivingLocations();
    $defaultLocation = $locations->firstWhere('id', (int) InventorySettings::current()->default_stock_location_id)
        ?: $locations->first()
        ?: InventorySettings::receivingLocation((int) $purchase->branch_id);
    $this->default_stock_location_id = (string) $defaultLocation->id;

    foreach ($this->purchase->items as $item) {
        $preferred = ProductLocationSetting::query()
            ->where('product_id', $item->product_id)
            ->where(fn ($query) => $query->where('branch_id', $this->purchase->branch_id)->orWhereNull('branch_id'))
            ->whereNotNull('preferred_receiving_location_id')
            ->orderByDesc('branch_id')
            ->value('preferred_receiving_location_id');

        $locationId = $preferred ?: $this->default_stock_location_id;

        if (! $this->availableReceivingLocations()->contains('id', (int) $locationId)) {
            $locationId = $this->default_stock_location_id;
        }

        $this->lines[$item->id] = [
            'quantity' => '0',
            'markup_percentage' => '20',
            'preview_selling_price' => auth()->user()->can('products.view_selling_price') ? (string) ($item->product?->selling_price ?? 0) : '',
            'stock_location_id' => (string) $locationId,
            'batch_number' => '',
            'expiry_date' => '',
            'notes' => '',
        ];
    }
});

$availableReceivingLocations = function () {
    $user = auth()->user();
    $branchId = (int) ($this->purchase?->branch_id ?: Purchase::find($this->purchase_id)?->branch_id);
    $locations = $user?->permittedStockLocations('can_receive', $branchId)
        ->filter(fn (StockLocation $location) => app(InventoryService::class)->canUserReceiveIntoLocation($user, $location))
        ->values() ?? collect();

    if ($locations->isNotEmpty()) {
        return $locations;
    }

    if ($user?->stockLocations()->exists()) {
        return collect();
    }

    if ($user?->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper'])) {
        return StockLocation::query()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->where('is_active', true)
            ->where('can_receive_stock', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()->filter(fn (StockLocation $location) => app(InventoryService::class)->canUserReceiveIntoLocation($user, $location))->values();
    }

    return collect();
};

$updatedDefaultStockLocationId = function ($value) {
    foreach ($this->lines as $itemId => $line) {
        $this->lines[$itemId]['stock_location_id'] = (string) $value;
    }
};

$addCostType = function (): void {
    abort_unless(auth()->user()?->hasAnyRole(['Super Admin', __('application.admin_4e7afebc')]), 403);
    $this->validate(['new_cost_type' => ['required', 'string', 'max:100']]);
    $name = trim($this->new_cost_type);
    if (mb_strtolower($name) === 'product cost') {
        $this->addError('new_cost_type', __('application.product_cost_is_already_included_in_goods_value_03a55cce'));

        return;
    }
    $type = PurchaseCostType::query()->firstOrCreate(
        ['company_id' => auth()->user()->company_id, 'name' => $name],
        ['is_active' => true],
    );
    $type->update(['is_active' => true]);
    $this->new_cost_type = '';
};

$addAdditionalCost = function (): void {
    $this->additional_costs[] = ['type_id' => '', 'amount' => '', 'payee' => '', 'payment_method' => '', 'payment_reference' => '', 'notes' => ''];
};

$removeAdditionalCost = function (int $index): void {
    unset($this->additional_costs[$index]);
    $this->additional_costs = array_values($this->additional_costs);
};

$receiveAll = function () {
    $purchase = Purchase::query()->with('items')->findOrFail($this->purchase_id);

    foreach ($purchase->items as $item) {
        $this->lines[$item->id]['quantity'] = (string) $item->remainingQuantity();
    }
};

$summary = function (): array {
    $purchase = Purchase::query()->with('items')->findOrFail($this->purchase_id);
    $selectedLines = 0;
    $remainingAfter = 0;
    $locations = collect();

    foreach ($purchase->items as $item) {
        $line = $this->lines[$item->id] ?? [];
        $lineQuantity = (float) ($line['quantity'] ?? 0);
        $remainingAfter += max(0, $item->remainingQuantity() - $lineQuantity);

        if ($lineQuantity <= 0) {
            continue;
        }

        $selectedLines++;
        $locations->push((int) ($line['stock_location_id'] ?? 0));
    }

    $additionalCents = collect($this->additional_costs)->sum(fn ($row) => is_numeric($row['amount'] ?? null) ? max(0, (int) round((float) $row['amount'] * 100)) : 0);
    $costing = app(GoodsReceiptCostingService::class)->calculate($purchase->items, $this->lines, $additionalCents);

    return $costing + [
        'selected_lines' => $selectedLines,
        'locations' => $locations->filter()->unique()->count(),
        'remaining_after' => $remainingAfter,
    ];
};

$canApplySuggestedPrice = fn () => auth()->user()?->can('products.edit')
    && auth()->user()?->can('products.edit_selling_price')
    && auth()->user()?->can('products.view_selling_price');

$applySuggestedPrice = function (int $itemId) {
    abort_unless($this->canApplySuggestedPrice(), 403);
    DB::transaction(function () use ($itemId) {
        $purchase = Purchase::query()->where('company_id', auth()->user()->company_id)->lockForUpdate()->findOrFail($this->purchase_id);
        abort_if(in_array($purchase->status, ['received', 'cancelled'], true), 403);
        $item = $purchase->items()->where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($itemId);
        $this->validateReceiving();
        $this->validate(["lines.{$itemId}.markup_percentage" => ['required', 'numeric', 'min:0', 'max:10000', 'decimal:0,2']]);
        if ((float) ($this->lines[$itemId]['quantity'] ?? 0) > $item->remainingQuantity()) {
            throw \Illuminate\Validation\ValidationException::withMessages(["lines.{$itemId}.quantity" => __('application.quantity_cannot_exceed_remaining_quantity_3e9107f7')]);
        }
        $costing = $this->summary();
        if (! isset($costing['rows'][$itemId])) {
            throw \Illuminate\Validation\ValidationException::withMessages(["lines.{$itemId}.quantity" => __('application.enter_a_received_quantity_before_applying_a_price_37f32923')]);
        }
        $product = Product::query()->where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($item->product_id);
        $price = $costing['rows'][$itemId]['suggested_selling_price'];
        $product->update(['selling_price' => $price]);
        $this->lines[$itemId]['preview_selling_price'] = (string) $price;
    });
    $this->purchase = $this->purchase->fresh(['items.product', 'items.purchaseUnit.measurementType', 'items.stockUnit', 'supplier', 'branch', 'creator']);
    $this->dispatch('hardex-notify', message: 'Suggested selling price applied.', tone: 'success');
};

$locationBreakdown = function () {
    $locations = $this->availableReceivingLocations()->keyBy('id');

    return collect($this->summary()['rows'])
        ->map(fn ($row, $itemId) => $row + ['stock_location_id' => (int) ($this->lines[$itemId]['stock_location_id'] ?? 0)])
        ->groupBy('stock_location_id')
        ->map(fn ($rows, $locationId) => [
            'name' => $locations->get((int) $locationId)?->name ?? 'Unknown',
            'quantity' => $rows->sum('stock_quantity'),
        ])
        ->values();
};

$openConfirmation = function () {
    $this->validateReceiving();
    $this->dispatch('open-modal', 'confirm-receiving');
};

$validateReceiving = function () {
    $locationIds = $this->availableReceivingLocations()->pluck('id')->map(fn ($id) => (string) $id)->all();

    $rules = [
        'grn_number' => ['required', 'string', 'max:255'],
        'received_date' => ['required', 'date'],
        'supplier_delivery_note_number' => ['nullable', 'string', 'max:255'],
        'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
        'default_stock_location_id' => ['required', Rule::in($locationIds)],
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['required', 'array'],
        'additional_costs' => ['array'],
        'additional_costs.*.type_id' => ['required', Rule::exists('purchase_cost_types', 'id')->where('company_id', auth()->user()->company_id)->where('is_active', true)->where('name', '!=', 'Product Cost')],
        'additional_costs.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        'additional_costs.*.payee' => ['nullable', 'string', 'max:255'],
        'additional_costs.*.payment_method' => ['nullable', new \App\Rules\AvailablePaymentMethod()],
        'additional_costs.*.payment_reference' => ['nullable', 'string', 'max:255'],
        'additional_costs.*.notes' => ['nullable', 'string', 'max:1000'],
        'lines.*.markup_percentage' => ['nullable', 'numeric', 'min:0', 'max:10000', 'decimal:0,2'],
        'lines.*.preview_selling_price' => ['nullable', 'numeric', 'min:0'],
        'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        'lines.*.stock_location_id' => ['required', Rule::in($locationIds)],
        'lines.*.notes' => ['nullable', 'string', 'max:1000'],
    ];

    $purchase = Purchase::query()->with('items.product')->findOrFail($this->purchase_id);

    foreach ($purchase->items as $item) {
        $isReceivingLine = (float) ($this->lines[$item->id]['quantity'] ?? 0) > 0;
        $rules["lines.{$item->id}.batch_number"] = $item->product?->tracks_batch && $isReceivingLine
            ? ['required', 'string', 'max:255']
            : (($item->product?->tracks_expiry)
                ? ['nullable', 'string', 'max:255']
                : ['nullable']);
        $rules["lines.{$item->id}.expiry_date"] = $item->product?->tracks_expiry && $isReceivingLine
            ? ['required', 'date', 'after_or_equal:received_date']
            : (($item->product?->tracks_expiry)
                ? ['nullable', 'date', 'after_or_equal:received_date']
                : ['nullable']);
    }

    $this->validate($rules, [
        'lines.*.batch_number.required' => __('application.batch_number_is_required_for_this_product_5c087d5b'),
        'lines.*.expiry_date.required' => __('application.expiry_date_is_required_for_this_product_11aead73'),
        'lines.*.expiry_date.after_or_equal' => __('application.expiry_date_cannot_be_earlier_than_the_receiving_date_a1805867'),
    ]);

    $hasQuantity = false;

    foreach ($purchase->items as $item) {
        $quantity = (float) ($this->lines[$item->id]['quantity'] ?? 0);

        if ($quantity <= 0) {
            continue;
        }

        $hasQuantity = true;

        if ($quantity > $item->remainingQuantity()) {
            $this->addError("lines.{$item->id}.quantity", 'Quantity cannot exceed remaining quantity.');
        }
    }

    if (! $hasQuantity) {
        $this->addError('lines', __('application.enter_at_least_one_quantity_to_receive_c00542ed'));
    }

    if ($this->getErrorBag()->any()) {
        throw \Illuminate\Validation\ValidationException::withMessages($this->getErrorBag()->toArray());
    }
};

$saveDraft = function (InventoryService $inventory) {
    $this->validateReceiving();

    $purchase = Purchase::query()->findOrFail($this->purchase_id);
    $inventory->receivePurchase($purchase, $this->lines, $this->received_date, auth()->id(), $this->notes, [
        'grn_number' => $this->grn_number,
        'grn_is_system_generated' => true,
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'draft',
        'additional_costs' => $this->additional_costs,
    ]);

    session()->flash('success', __('application.goods_receipt_draft_saved_882ef2a5'));
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

$postReceipt = function (InventoryService $inventory) {
    $this->validateReceiving();

    $purchase = Purchase::query()->findOrFail($this->purchase_id);
    $inventory->receivePurchase($purchase, $this->lines, $this->received_date, auth()->id(), $this->notes, [
        'grn_number' => $this->grn_number,
        'grn_is_system_generated' => true,
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'posted',
        'additional_costs' => $this->additional_costs,
    ]);

    session()->flash('success', __('application.purchase_received_successfully_206f7979'));
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

?>

<div>
    @php
        $locations = $this->availableReceivingLocations();
        $costTypes = PurchaseCostType::query()->where('is_active', true)->where('name', '!=', 'Product Cost')->orderBy('name')->get();
        $locationOptions = $locations->keyBy('id');
        $summary = $this->summary();
        $totalOrdered = $purchase->items->sum('ordered_quantity');
        $totalReceived = $purchase->items->sum('received_quantity');
        $totalRemaining = $purchase->items->sum(fn ($item) => $item->remainingQuantity());
        $showBatchColumn = $purchase->items->contains(fn ($item) => (bool) ($item->product?->tracks_batch || $item->product?->tracks_expiry));
        $showExpiryColumn = $purchase->items->contains(fn ($item) => (bool) $item->product?->tracks_expiry);
    @endphp

    <x-page-header title="{{ __('application.receive_purchase_order_fc68a002') }}" description="{{ __('application.receive_purchased_goods_into_the_correct_stock_location_6966cf8f') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Receive' => null]">
        <button type="button" wire:click="receiveAll" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.receive_all_1e3ea539') }}</button>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-4">
        @foreach ([
            'Purchase Number' => $purchase->reference_number,
            'Supplier' => $purchase->supplier?->name,
            'Branch' => $purchase->branch?->name,
            'Purchase Date' => $purchase->purchase_date?->locale(app()->getLocale())->translatedFormat('d M Y'),
            'Ordered By' => $purchase->creator?->name,
            'Purchase Status' => \App\Support\UiText::status($purchase->status),
            'Total Ordered Quantity' => \App\Support\NumberFormatter::quantity($totalOrdered),
            'Previously Received Quantity' => \App\Support\NumberFormatter::quantity($totalReceived),
            'Remaining Quantity' => \App\Support\NumberFormatter::quantity($totalRemaining),
        ] as $label => $value)
            <x-card>
                <p class="text-xs font-bold uppercase text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-base font-black text-navy-900 dark:text-white">{{ $value ?: '-' }}</p>
            </x-card>
        @endforeach
    </div>

    <x-card class="mt-6">
        <form class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-form-input label="{{ __('application.goods_receipt_number_f5f03df7') }}" name="grn_number" wire:model="grn_number" readonly required />
                    <p class="mt-1 text-xs font-semibold text-slate-500">{{ __('application.generated_automatically_62571b7b') }}</p>
                </div>
                <x-form-input label="{{ __('application.receiving_date_64d0be44') }}" name="received_date" type="date" wire:model="received_date" required />
                <x-form-input label="{{ __('application.supplier_delivery_note_number_61ad16c8') }}" name="supplier_delivery_note_number" wire:model="supplier_delivery_note_number" />
                <x-form-input label="{{ __('application.supplier_invoice_number_e205f5e1') }}" name="supplier_invoice_number" wire:model="supplier_invoice_number" />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 xl:col-span-2">{{ __('application.receive_all_into_b2213487') }}
                    <select wire:model.live="default_stock_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                        @endforeach
                    </select>
                    @error('default_stock_location_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 xl:col-span-2">{{ __('application.notes_70440046') }}
                    <textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
            </div>

            @error('lines') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                <table class="{{ $showBatchColumn || $showExpiryColumn ? 'min-w-[1320px]' : 'min-w-[1040px]' }} w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-3 py-3">{{ __('application.product_dd3b86d1') }}</th>
                            <th class="px-3 py-3">{{ __('application.sku_d59192b9') }}</th>
                            <th class="px-3 py-3">{{ __('application.purchase_unit_9e240a38') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('application.ordered_quantity_327000f9') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('application.previously_received_1b311249') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('application.remaining_quantity_52febf6a') }}</th>
                            <th class="px-3 py-3">{{ __('application.received_quantity_51c18ce9') }}</th>
                            <th class="px-3 py-3">{{ __('application.stock_increase_3e7c9512') }}</th>
                            <th class="px-3 py-3">{{ __('application.supplier_buying_price_a068bb29') }}</th>
                            <th class="px-3 py-3">{{ __('application.receive_into_location_b6611b59') }}</th>
                            @if ($showBatchColumn)
                                <th class="px-3 py-3">{{ __('application.batch_number_86b217fd') }}</th>
                            @endif
                            @if ($showExpiryColumn)
                                <th class="px-3 py-3">{{ __('application.expiry_date_8d007050') }}</th>
                            @endif
                            <th class="px-3 py-3">{{ __('application.line_notes_6e8da3c9') }}</th>
                            <th class="px-3 py-3">{{ __('application.status_bae7d5be') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($purchase->items as $item)
                            @php($isReceivingLine = (float) ($lines[$item->id]['quantity'] ?? 0) > 0)
                            <tr class="align-top">
                                <td class="px-3 py-3 font-black">
                                    {{ $item->product?->displayName() }}
                                    @if ($item->sizeLabel())
                                        <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ __('application.size_86251551') }} {{ $item->sizeLabel() }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-mono">{{ $item->product?->sku }}</td>
                                <td class="px-3 py-3">
                                    {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}
                                    <span class="mt-1 block text-[11px] text-slate-500">1 {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->purchaseFactor()) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</span>
                                </td>
                                <td class="px-3 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }}</td>
                                <td class="px-3 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }}</td>
                                <td class="px-3 py-3 text-right font-bold">{{ \App\Support\NumberFormatter::quantity($item->remainingQuantity()) }}</td>
                                <td class="px-3 py-3">
                                    <input wire:model.live="lines.{{ $item->id }}.quantity" type="number" step="{{ $item->purchaseUnit?->measurementType?->code === \App\Models\MeasurementType::COUNT ? '1' : '0.0001' }}" max="{{ $item->remainingQuantity() }}" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    @error("lines.{$item->id}.quantity") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3 font-bold">
                                    {{ \App\Support\NumberFormatter::quantity($item->stockQuantity((float) ($lines[$item->id]['quantity'] ?? 0))) }}
                                    {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}
                                </td>
                                <td class="px-3 py-3">
                                    <p>TZS {{ \App\Support\NumberFormatter::money($item->cost_price) }} {{ __('application.purchase_unit_5725b9e9') }}</p>
                                    @if ($costRow = $summary['rows'][$item->id] ?? null)
                                        <dl class="mt-2 space-y-1 text-xs">
                                            <dt>{{ __('application.supplier_buying_price_base_unit_46a3989c') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($costRow['supplier_base_unit_cost']) }}</dd>
                                            <dt>{{ __('application.landed_cost_unit_5a3f32e3') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($costRow['landed_cost_per_unit']) }}</dd>
                                            <dt class="font-black">{{ __('application.final_unit_cost_base_unit_0a7533e5') }}</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($costRow['final_unit_cost']) }}</dd>
                                        </dl>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <select wire:model="lines.{{ $item->id }}.stock_location_id" class="w-52 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$item->id}.stock_location_id") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                @if ($showBatchColumn)
                                    <td class="px-3 py-3">
                                        @if ($item->product?->tracks_batch || $item->product?->tracks_expiry)
                                            <input wire:model="lines.{{ $item->id }}.batch_number" @required($item->product?->tracks_batch && $isReceivingLine) class="w-36 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                            @if ($item->product?->tracks_batch)
                                                <span class="mt-1 block text-xs font-semibold text-slate-500">{{ __('application.required_eed6bfb4') }}</span>
                                            @else
                                                <span class="mt-1 block text-xs font-semibold text-slate-500">{{ __('application.optional_0c6c4102') }}</span>
                                            @endif
                                            @error("lines.{$item->id}.batch_number") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                        @else
                                            <span class="text-xs font-semibold text-slate-400">{{ __('application.not_required_45240f7a') }}</span>
                                        @endif
                                    </td>
                                @endif
                                @if ($showExpiryColumn)
                                    <td class="px-3 py-3">
                                        @if ($item->product?->tracks_expiry)
                                            <input wire:model="lines.{{ $item->id }}.expiry_date" type="date" min="{{ $received_date }}" @required($isReceivingLine) class="w-40 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                            <span class="mt-1 block text-xs font-semibold text-slate-500">{{ __('application.required_eed6bfb4') }}</span>
                                            @error("lines.{$item->id}.expiry_date") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                        @else
                                            <span class="text-xs font-semibold text-slate-400">{{ __('application.not_required_45240f7a') }}</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-3 py-3"><input wire:model="lines.{{ $item->id }}.notes" class="w-48 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                                <td class="px-3 py-3"><span class="{{ $item->remainingQuantity() > 0 ? 'badge-warning' : 'badge-success' }}">{{ $item->remainingQuantity() > 0 ? __('application.open_cf9b7706') : __('terminology.done_e9b450d1') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <div class="flex items-center justify-between gap-3">
                    <div><h2 class="font-black">{{ __('application.additional_landed_costs_6329229b') }}</h2><p class="text-xs text-slate-500">{{ __('application.supplier_cost_is_the_price_paid_for_the_goods_landed_cost_includes_expenses_92918faa') }}</p></div>
                    <button type="button" wire:click="addAdditionalCost" class="rounded-lg bg-cyan-700 px-3 py-2 text-xs font-bold text-white">{{ __('application.add_cost_065a0b40') }}</button>
                </div>
                @if (auth()->user()?->hasAnyRole(['Super Admin', 'Admin']))
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <label class="text-xs font-bold">{{ __('application.new_cost_type_ef89b91f') }}
                            <input wire:model="new_cost_type" maxlength="100" class="mt-1 block rounded-lg border border-slate-200 p-2 dark:bg-navy-950" placeholder="{{ __('application.e_g_port_charges_464d9139') }}">
                        </label>
                        <button type="button" wire:click="addCostType" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700">{{ __('application.add_type_de185074') }}</button>
                        @error('new_cost_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endif
                @foreach ($additional_costs as $index => $cost)
                    <div wire:key="receipt-cost-{{ $index }}" class="mt-3 grid gap-2 md:grid-cols-3 xl:grid-cols-7">
                        <label class="text-xs font-bold">{{ __('application.cost_type_64e3d203') }}
                            <select wire:model="additional_costs.{{ $index }}.type_id" class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 dark:bg-navy-950">
                                <option value="">{{ __('application.select_type_905d0122') }}</option>
                                @foreach ($costTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
                            </select>
                            @error("additional_costs.{$index}.type_id") <span class="text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="text-xs font-bold">{{ __('application.amount_43dc8532') }}
                            <input wire:model.live="additional_costs.{{ $index }}.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950">
                            @error("additional_costs.{$index}.amount") <span class="text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="text-xs font-bold">{{ __('application.paid_to_payee_6ff4ebdf') }}<input wire:model="additional_costs.{{ $index }}.payee" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <label class="text-xs font-bold">{{ __('application.payment_method_f383f6a2') }}<select wire:model="additional_costs.{{ $index }}.payment_method" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"><option value=""></option><x-payment-method-options :branch-id="$branch_id ?? null" /></select></label>
                        <label class="text-xs font-bold">{{ __('application.payment_reference_80e69501') }}<input wire:model="additional_costs.{{ $index }}.payment_reference" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <label class="text-xs font-bold">{{ __('application.notes_70440046') }}<input wire:model="additional_costs.{{ $index }}.notes" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <button type="button" wire:click="removeAdditionalCost({{ $index }})" class="self-end rounded-lg border border-red-300 p-2 text-xs font-bold text-red-700">{{ __('application.remove_e963907d') }}</button>
                    </div>
                @endforeach
            </div>

            <p class="text-sm text-slate-500">{{ __('application.additional_costs_are_shared_by_actual_received_base_units_supplier_prices_s_4d80cbca') }}</p>
            @if (auth()->user()->can('products.view_selling_price'))
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($purchase->items as $item)
                        @if ($costRow = $summary['rows'][$item->id] ?? null)
                            <div wire:key="pricing-{{ $item->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                <h3 class="font-black">{{ $item->product?->displayName() }}</h3>
                                <p class="text-xs text-slate-500">{{ __('application.prices_and_profit_below_are_per_547f7ea6') }} {{ $item->product?->sellingUnit?->short_name ?: $item->stockUnit?->short_name ?: 'selling unit' }}.</p>
                                <p>{{ __('application.final_unit_cost_tzs_4ee1f9e4') }} {{ \App\Support\NumberFormatter::money($costRow['final_selling_unit_cost']) }}</p>
                                <label class="mt-3 block text-sm font-bold">{{ __('application.markup_custom_3c15db3b') }}
                                    <input wire:model.live.debounce.300ms="lines.{{ $item->id }}.markup_percentage" type="number" min="0" max="10000" step="0.01" class="w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950">
                                </label>
                                @error("lines.{$item->id}.markup_percentage") <p class="text-red-600">{{ $message }}</p> @enderror
                                <div class="my-2 flex flex-wrap gap-2">
                                    @foreach ([10, 15, 20, 25, 30] as $preset)
                                        <button type="button" wire:click="$set('lines.{{ $item->id }}.markup_percentage', '{{ $preset }}')" class="rounded border px-2 py-1 text-xs">{{ $preset }}%</button>
                                    @endforeach
                                </div>
                                <p class="font-bold">{{ __('application.suggested_selling_price_tzs_49da624d') }} {{ \App\Support\NumberFormatter::money($costRow['suggested_selling_price'] ?? 0) }}</p>
                                <p class="text-xs text-slate-500">{{ __('application.advisory_only_receiving_does_not_update_product_prices_b5e1e5b8') }}</p>
                                @if ($this->canApplySuggestedPrice())
                                    <button type="button" wire:click="applySuggestedPrice({{ $item->id }})" wire:loading.attr="disabled" class="my-2 rounded-lg bg-build-orange px-3 py-2 font-bold text-white">{{ __('application.apply_suggested_price_c6866efb') }}</button>
                                @endif
                                <label class="block text-sm font-bold">{{ __('application.selling_price_for_profit_preview_da30de58') }}
                                    <input wire:model.live.debounce.300ms="lines.{{ $item->id }}.preview_selling_price" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950">
                                </label>
                                @error("lines.{$item->id}.preview_selling_price") <p class="text-red-600">{{ $message }}</p> @enderror
                                <p>{{ __('application.profit_per_unit_tzs_ef140d0f') }} {{ \App\Support\NumberFormatter::money($costRow['profit_per_unit']) }}</p>
                                <p>{{ __('application.profit_margin_1f922692') }} {{ $costRow['profit_margin'] }}%</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="grid gap-3 md:grid-cols-5">
                @foreach ([
                    'Selected Products' => number_format($summary['selected_lines']),
                    'Total Received Qty (Base Units)' => \App\Support\NumberFormatter::quantity($summary['quantity']),
                    'Purchase Goods Value' => 'TZS '.\App\Support\NumberFormatter::money($summary['cost']),
                    'Additional Costs' => 'TZS '.\App\Support\NumberFormatter::money($summary['additional']),
                    'Landed Cost / Unit' => 'TZS '.\App\Support\NumberFormatter::money($summary['landed_per_unit']),
                    'Total Landed Value' => 'TZS '.\App\Support\NumberFormatter::money($summary['landed']),
                    'Receiving Locations' => number_format($summary['locations']),
                    'Remaining After Receipt' => \App\Support\NumberFormatter::quantity($summary['remaining_after']),
                ] as $label => $value)
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs font-bold uppercase text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-lg font-black">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="openConfirmation" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.review_receipt_88081878') }}</button>
                <a href="{{ route('purchases.show', $purchase->id) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
            </div>
        </form>
    </x-card>

    <x-modal name="confirm-receiving" maxWidth="2xl">
        <div class="p-5">
            <h2 class="text-lg font-black">{{ __('application.confirm_purchase_receiving_4cab8910') }}</h2>
            <div class="mt-4 space-y-2 text-sm">
                <p><span class="font-bold">{{ __('application.purchase_bf2ae324') }}</span> {{ $purchase->reference_number }}</p>
                <p><span class="font-bold">{{ __('application.supplier_1c8a2129') }}</span> {{ $purchase->supplier?->name }}</p>
                <p><span class="font-bold">{{ __('application.receiving_date_e9479d5b') }}</span> {{ $received_date }}</p>
                <p><span class="font-bold">{{ __('application.total_received_qty_base_units_906e6651') }}</span> {{ \App\Support\NumberFormatter::quantity($summary['quantity']) }}</p>
                <p><span class="font-bold">{{ __('application.goods_value_d79c9d5a') }}</span> TZS {{ \App\Support\NumberFormatter::money($summary['cost']) }}</p>
                <p><span class="font-bold">{{ __('application.additional_costs_1c3f83a3') }}</span> TZS {{ \App\Support\NumberFormatter::money($summary['additional']) }}</p>
                <p><span class="font-bold">{{ __('application.total_landed_value_8ba1a19b') }}</span> TZS {{ \App\Support\NumberFormatter::money($summary['landed']) }}</p>
            </div>
            <div class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                @foreach ($this->locationBreakdown() as $row)
                    <div class="flex justify-between gap-4 py-1 text-sm">
                        <span class="font-bold">{{ $row['name'] }}</span>
                        <span>{{ \App\Support\NumberFormatter::quantity($row['quantity']) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'confirm-receiving')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</button>
                <button type="button" wire:click="saveDraft" class="rounded-xl border border-cyan-200 px-4 py-2.5 text-sm font-black text-cyan-700 dark:border-cyan-500/30 dark:text-cyan-200">{{ __('application.save_as_draft_c031325d') }}</button>
                <button type="button" wire:click="postReceipt" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.confirm_and_post_receipt_160867be') }}</button>
            </div>
        </div>
    </x-modal>
</div>
