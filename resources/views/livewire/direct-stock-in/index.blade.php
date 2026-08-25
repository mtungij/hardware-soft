<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Services\ProductUnitConversionService;
use App\Support\InventorySettings;
use App\Support\AuthorizationScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'branch_id' => '',
    'product_id' => '',
    'stock_in_unit_options' => [],
    'stock_in_lines' => [],
    'quantity' => '',
    'cost_price' => '',
    'selling_price' => '',
    'stock_location_id' => '',
    'reason' => 'Opening Stock',
    'notes' => '',
    'movement_date' => '',
    'idempotency_key' => '',
]);

mount(function (InventoryService $inventory) {
    abort_unless(auth()->user()->can('stock.direct_stock_in'), 403);
    abort_unless(InventorySettings::directStockInAllowed(), 403);

    $this->branch_id = (string) InventorySettings::branchId();
    $this->stock_location_id = (string) InventorySettings::receivingLocation((int) $this->branch_id)->id;
    $this->movement_date = now()->toDateString();
    $this->idempotency_key = (string) Str::uuid();
});

rules(fn () => [
    'branch_id' => ['required', 'exists:branches,id'],
    'product_id' => ['required', 'exists:products,id'],
    'quantity' => ['nullable', 'numeric', 'gt:0'],
    'cost_price' => ['required', 'numeric', 'min:0'],
    'selling_price' => ['nullable', 'numeric', 'min:0'],
    'stock_location_id' => ['required', Rule::exists('stock_locations', 'id')],
    'reason' => ['required', Rule::in(['Opening Stock', 'Direct Purchase', 'Manual Entry', 'Stock Correction', 'Other'])],
    'notes' => ['nullable', 'string', 'max:1000'],
    'movement_date' => ['required', 'date'],
    'idempotency_key' => ['required', 'uuid'],
    'stock_in_lines' => ['required', 'array', 'min:1'],
    'stock_in_lines.*.product_unit_conversion_id' => ['nullable', 'integer'],
    'stock_in_lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
    'stock_in_lines.*.buying_price' => ['nullable', 'numeric', 'min:0'],
    'stock_in_lines.*.selling_price' => ['nullable', 'numeric', 'min:0'],
]);

$canUpdateSellingPrice = fn (): bool => auth()->user()?->can('products.edit_selling_price') ?? false;

$updatedProductId = function (string $value) {
    $this->selectProduct($value);
};

$updatedQuantity = function ($value) {
    if (isset($this->stock_in_lines[0])) {
        $this->stock_in_lines[0]['quantity'] = $value;
    }
};

$updatedCostPrice = function ($value) {
    if (isset($this->stock_in_lines[0])) {
        $this->stock_in_lines[0]['buying_price'] = $value;
    }
};

$updatedSellingPrice = function ($value) {
    if (isset($this->stock_in_lines[0])) {
        $this->stock_in_lines[0]['selling_price'] = $value;
    }
};

$selectProduct = function (string $productId) {
    $product = $productId ? Product::query()->with('unit')->find($productId) : null;

    $this->product_id = $product ? (string) $product->id : '';
    $conversions = $product ? app(ProductUnitConversionService::class)->purchasable($product) : collect();
    $this->stock_in_unit_options = $conversions
        ->map(fn ($conversion): array => [
                'id' => (string) $conversion->id,
                'unit_name' => $conversion->unit?->name,
                'unit_code' => $conversion->unit?->short_name,
                'conversion_factor' => (string) $conversion->conversion_factor,
                'purchase_price' => $conversion->purchase_price !== null ? (string) $conversion->purchase_price : '',
                'retail_price' => $conversion->retail_price !== null ? (string) $conversion->retail_price : '',
                'can_sell' => (bool) $conversion->can_sell,
            ])->values()->all();
    $baseRow = $product ? [[
        'key' => (string) Str::uuid(),
        'product_unit_conversion_id' => '',
        'transaction_unit_id' => (string) $product->unit_id,
        'unit_name' => $product->unit?->name,
        'unit_code' => $product->unit?->short_name,
        'conversion_factor' => '1',
        'quantity' => '',
        'buying_price' => (string) $product->buying_price,
        'selling_price' => (string) $product->selling_price,
        'can_sell' => true,
    ]] : [];

    $alternativeRows = $conversions->map(fn ($conversion): array => [
        'key' => (string) Str::uuid(),
        'product_unit_conversion_id' => (string) $conversion->id,
        'transaction_unit_id' => (string) $conversion->unit_id,
        'unit_name' => $conversion->unit?->name,
        'unit_code' => $conversion->unit?->short_name,
        'conversion_factor' => (string) $conversion->conversion_factor,
        'quantity' => '',
        'buying_price' => $conversion->purchase_price !== null ? (string) $conversion->purchase_price : '',
        'selling_price' => $conversion->can_sell && $conversion->retail_price !== null ? (string) $conversion->retail_price : '',
        'can_sell' => (bool) $conversion->can_sell,
    ])->all();

    $this->stock_in_lines = [...$baseRow, ...$alternativeRows];
    $this->quantity = '';
    $this->cost_price = $product ? (string) $product->buying_price : '';
    $this->selling_price = $product ? (string) $product->selling_price : '';
    $this->resetErrorBag();
};

$save = function (InventoryService $inventory) {
    abort_unless(auth()->user()->can('stock.direct_stock_in'), 403);
    abort_unless(InventorySettings::directStockInAllowed(), 403);

    $this->resetErrorBag();

    try {
        $data = $this->validate();
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first() ?: 'Please fix the validation errors and try again.');

        throw $exception;
    }

    $data['stock_in_lines'] = array_values(array_filter(
        $data['stock_in_lines'],
        fn (array $line): bool => filled($line['quantity'] ?? null),
    ));

    if ($data['stock_in_lines'] === []) {
        session()->flash('error', 'Enter a quantity for at least one stock unit.');

        throw ValidationException::withMessages([
            'stock_in_lines' => 'Enter a quantity greater than zero for at least one stock unit.',
        ]);
    }

    foreach ($data['stock_in_lines'] as $index => $line) {
        if (! filled($line['buying_price'] ?? null)) {
            throw ValidationException::withMessages([
                "stock_in_lines.{$index}.buying_price" => 'Buying Price is required for each quantity entered.',
            ]);
        }
    }

    if (! $this->canUpdateSellingPrice()) {
        foreach ($data['stock_in_lines'] as &$line) {
            unset($line['selling_price']);
        }
        unset($line);
    }

    if (! InventorySettings::warehouseEnabled()) {
        $data['stock_location_id'] = InventorySettings::receivingLocation((int) $data['branch_id'])->id;
    }

    abort_unless(AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $data['stock_location_id'], 'can_receive'), 403);

    try {
        $inventory->directStockInBatch($data, auth()->id());
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first() ?: 'Direct stock in could not be saved.');

        throw $exception;
    } catch (\Throwable $exception) {
        report($exception);

        $this->addError('save', 'Direct stock in could not be saved: '.$exception->getMessage());
        session()->flash('error', 'Direct stock in could not be saved: '.$exception->getMessage());

        return;
    }

    $this->reset(['product_id', 'stock_in_unit_options', 'stock_in_lines', 'quantity', 'cost_price', 'selling_price', 'notes']);
    $this->reason = 'Opening Stock';
    $this->movement_date = now()->toDateString();
    $this->stock_location_id = (string) InventorySettings::receivingLocation((int) $this->branch_id)->id;
    $this->idempotency_key = (string) Str::uuid();
    $this->resetPage();

    session()->flash('success', 'Direct stock in saved.');
};

?>

<div>
    <x-page-header title="Direct Stock In" description="Add stock directly without supplier or purchase order." :breadcrumbs="['Dashboard' => route('dashboard'), 'Direct Stock In' => null]" />

    @php
        $canUpdateSellingPrice = $this->canUpdateSellingPrice();
        $selectedProduct = filled($product_id) ? Product::query()->with('unit')->find($product_id) : null;
        $baseUnitLabel = $selectedProduct?->unit?->name ?: $selectedProduct?->unit?->short_name ?: 'base units';
        $normalizedStockTotal = collect($stock_in_lines)->sum(function (array $line): float {
            $quantity = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 0;
            $factor = is_numeric($line['conversion_factor'] ?? null) ? (float) $line['conversion_factor'] : 0;

            return $quantity * $factor;
        });
        $purchaseValueTotal = collect($stock_in_lines)->sum(function (array $line): float {
            $quantity = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 0;
            $price = is_numeric($line['buying_price'] ?? null) ? (float) $line['buying_price'] : 0;

            return $quantity * $price;
        });
        $productSelectOptions = [
            'placeholder' => 'Search or select product',
            'hasSearch' => true,
            'minSearchLength' => 0,
            'searchPlaceholder' => 'Search product by name or SKU',
            'searchNoResultText' => 'No product found',
            'optionAllowEmptyOption' => true,
            'toggleClasses' => 'relative py-2.5 ps-3 pe-9 flex w-full cursor-pointer rounded-lg border border-slate-200 bg-white text-start text-sm text-slate-800 shadow-sm outline-none transition before:absolute before:inset-0 before:z-1 focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/20 disabled:pointer-events-none disabled:opacity-60 dark:border-slate-700 dark:bg-navy-950 dark:text-white dark:focus:border-cyan-400',
            'dropdownClasses' => 'z-[80] mt-2 max-h-72 w-full overflow-hidden overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900',
            'optionClasses' => 'cursor-pointer rounded-lg px-3 py-2 text-sm text-slate-800 hover:bg-cyan-50 focus:bg-cyan-50 hs-selected:bg-cyan-500 hs-selected:text-white dark:text-slate-100 dark:hover:bg-cyan-500/10 dark:focus:bg-cyan-500/10 dark:hs-selected:bg-cyan-500',
            'searchClasses' => 'block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none ring-cyan-500/20 placeholder:text-slate-400 focus:border-cyan-500 focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-400',
            'searchWrapperClasses' => 'sticky top-0 z-10 bg-white p-1 dark:bg-slate-900',
            'dropdownScope' => 'parent',
        ];
    @endphp

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card title="Stock Entry" description="This creates a positive stock movement. Product quantity is calculated from movements.">
            <form wire:submit.prevent="save" class="space-y-4">
                @if (session('success'))
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                        {{ session('success') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        {{ session('error') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        <p class="font-black">Please fix these errors:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <label class="block text-sm font-bold">Product
                    <select
                        wire:model.live="product_id"
                        wire:key="direct-stock-in-product-select"
                        data-hs-select='@json($productSelectOptions)'
                        class="hidden"
                    >
                        <option value="" @selected(blank($product_id))>Select product</option>
                        @foreach (Product::with('size')->where('status', 'active')->orderBy('name')->get() as $product)
                            <option value="{{ $product->id }}" @selected((string) $product_id === (string) $product->id)>{{ $product->displayNameWithSize() }} / {{ $product->sku }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <x-form-input label="Movement Date" name="movement_date" type="date" wire:model="movement_date" required />

                @if ($selectedProduct)
                    <section class="space-y-3" wire:key="direct-stock-in-lines-{{ $product_id }}">
                        <div>
                            <h3 class="text-sm font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">Stock Quantities</h3>
                            <p class="mt-1 text-xs text-slate-500">Enter each package type received. Inventory will be stored in {{ $baseUnitLabel }}.</p>
                        </div>

                        @foreach ($stock_in_lines as $index => $line)
                            @php
                                $lineQuantity = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 0;
                                $lineFactor = is_numeric($line['conversion_factor'] ?? null) ? (float) $line['conversion_factor'] : 0;
                                $lineBaseQuantity = $lineQuantity * $lineFactor;
                                $lineValue = $lineQuantity * (is_numeric($line['buying_price'] ?? null) ? (float) $line['buying_price'] : 0);
                                $lineUnitName = $line['unit_name'] ?? 'Unit';
                                $lineUnitCode = $line['unit_code'] ?: $lineUnitName;
                                $authoritativeSellingPrice = $index === 0
                                    ? $selectedProduct->selling_price
                                    : collect($stock_in_unit_options)->firstWhere('id', (string) ($line['product_unit_conversion_id'] ?? ''))['retail_price'] ?? '';
                            @endphp
                            <div wire:key="direct-stock-in-line-{{ $line['key'] }}" class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-700 dark:bg-slate-900/60">
                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[110px_1fr_1fr_1fr] xl:items-start">
                                    <div>
                                        @if ($index === 0)
                                            <p class="text-sm font-black">{{ $lineUnitName }}</p>
                                            <p class="text-xs text-slate-500">Base Unit</p>
                                        @else
                                            <p class="text-sm font-black">{{ $lineUnitName }}</p>
                                            <p class="mt-1 text-xs text-slate-500">1 {{ $lineUnitName }} = {{ \App\Support\NumberFormatter::quantity($lineFactor) }} {{ $baseUnitLabel }}</p>
                                            @error("stock_in_lines.{$index}.product_unit_conversion_id") <span class="erp-error">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                    <label class="text-xs font-bold">Quantity{{ filled($lineUnitCode) ? ' / '.$lineUnitCode : '' }}
                                        <input type="number" step="0.0001" wire:model.live.debounce.250ms="stock_in_lines.{{ $index }}.quantity" class="erp-input mt-1">
                                        @error("stock_in_lines.{$index}.quantity") <span class="erp-error">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="text-xs font-bold">Buying Price{{ filled($lineUnitCode) ? ' / '.$lineUnitCode : '' }}
                                        <input type="number" step="0.01" wire:model.live.debounce.250ms="stock_in_lines.{{ $index }}.buying_price" class="erp-input mt-1">
                                        @error("stock_in_lines.{$index}.buying_price") <span class="erp-error">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="text-xs font-bold">Selling Price{{ filled($lineUnitCode) ? ' / '.$lineUnitCode : '' }}
                                        @if ($canUpdateSellingPrice && ($line['can_sell'] ?? false))
                                            <input type="number" step="0.01" wire:model="stock_in_lines.{{ $index }}.selling_price" class="erp-input mt-1">
                                        @else
                                            <div class="erp-input mt-1 bg-slate-100 dark:bg-slate-800">
                                                {{ ($line['can_sell'] ?? false) && filled($authoritativeSellingPrice) ? 'TZS '.\App\Support\NumberFormatter::money($authoritativeSellingPrice) : 'Not enabled for sale' }}
                                            </div>
                                        @endif
                                        @error("stock_in_lines.{$index}.selling_price") <span class="erp-error">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                                <p class="mt-2 text-xs font-semibold text-cyan-700 dark:text-cyan-300">
                                    @if ($index === 0)
                                        {{ \App\Support\NumberFormatter::quantity($lineQuantity) }} {{ $lineUnitName }} = {{ \App\Support\NumberFormatter::quantity($lineBaseQuantity) }} {{ $baseUnitLabel }}
                                    @else
                                        {{ \App\Support\NumberFormatter::quantity($lineQuantity) }} {{ $lineUnitName }} × {{ \App\Support\NumberFormatter::quantity($lineFactor) }} {{ $baseUnitLabel }} = {{ \App\Support\NumberFormatter::quantity($lineBaseQuantity) }} {{ $baseUnitLabel }}
                                    @endif
                                    · TZS {{ \App\Support\NumberFormatter::money($lineValue) }}
                                </p>
                            </div>
                        @endforeach

                        @if ($stock_in_unit_options === [])
                            <p class="text-xs font-semibold text-slate-500">No additional purchase units are configured for this product.</p>
                        @endif

                        <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10">
                            <p class="text-xs font-bold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Summary</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <div><span class="text-xs text-slate-500">Normalized Stock to Add</span><p class="font-black">{{ \App\Support\NumberFormatter::quantity($normalizedStockTotal) }} {{ $baseUnitLabel }}</p></div>
                                <div><span class="text-xs text-slate-500">Total Purchase Value</span><p class="font-black">TZS {{ \App\Support\NumberFormatter::money($purchaseValueTotal) }}</p></div>
                            </div>
                        </div>
                    </section>
                @endif

                @if (InventorySettings::warehouseEnabled())
                    <label class="block text-sm font-bold">Stock Location
                        <select wire:model="stock_location_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            @foreach (StockLocation::whereIn('id', AuthorizationScope::stockLocationIds(auth()->user(), 'can_receive'))->where('branch_id', $branch_id)->where('status', 'active')->orderBy('type')->get() as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <x-alert type="info">Warehouse is disabled. Stock will enter Dispensing Area automatically.</x-alert>
                @endif

                <label class="block text-sm font-bold">Reason
                    <select wire:model="reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (['Opening Stock', 'Direct Purchase', 'Manual Entry', 'Stock Correction', 'Other'] as $reasonOption)
                            <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                        @endforeach
                    </select>
                </label>

                <x-form-textarea label="Notes" name="notes" wire:model="notes" />

                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="w-full rounded-xl bg-cyan-500 px-4 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Save Direct Stock In</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </button>
            </form>
        </x-card>

        <x-card title="Recent Direct Stock In">
            @php
                $recentEntries = StockMovement::query()
                    ->with(['product', 'stockLocation', 'creator'])
                    ->where('movement_type', 'direct_stock_in')
                    ->latest('id')
                    ->limit(120)
                    ->get()
                    ->groupBy(fn (StockMovement $movement) => $movement->posting_reference ?: 'legacy-'.$movement->id)
                    ->take(12);
            @endphp
            <x-table :headers="['Reference', 'Date', 'Product', 'Location', 'Transaction Breakdown', 'Normalized Qty', 'Total Cost', 'Reason', 'Created By']">
                @forelse ($recentEntries as $reference => $entryMovements)
                    @php
                        $entryMovements = $entryMovements->sortBy('id')->values();
                        $movement = $entryMovements->first();
                        $breakdown = $entryMovements->map(function (StockMovement $line): string {
                            $quantity = $line->transaction_quantity ?? $line->quantity;
                            $unit = $line->transaction_unit_code_snapshot ?: $line->transaction_unit_name_snapshot ?: $line->product?->unit?->short_name;

                            return \App\Support\NumberFormatter::quantity($quantity).' '.$unit;
                        })->join(' + ');
                        $normalizedQuantity = $entryMovements->sum(fn (StockMovement $line) => (float) $line->quantity_in);
                        $totalCost = $entryMovements->sum(fn (StockMovement $line) => (float) $line->quantity_in * (float) $line->unit_cost);
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ str($reference)->startsWith('legacy-') ? 'Legacy #'.$movement->id : $reference }}</td>
                        <td class="px-4 py-3">{{ $movement->movement_date?->format('d M Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $movement->product?->displayNameWithSize() }}</td>
                        <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                        <td class="px-4 py-3">{{ $breakdown }}</td>
                        <td class="px-4 py-3 font-bold">{{ \App\Support\NumberFormatter::quantity($normalizedQuantity) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</td>
                        <td class="px-4 py-3">{{ str($movement->notes)->before(' - ') }}</td>
                        <td class="px-4 py-3">{{ $movement->creator?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-slate-500">No direct stock entries found.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
