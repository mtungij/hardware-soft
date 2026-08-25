<section class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/5 md:col-span-2 xl:col-span-3">
    @php
        $baseUnitForConversions = filled($unit_id) ? \App\Models\Unit::find($unit_id) : null;
        $conversionUnits = filled($measurement_type_id)
            ? \App\Models\Unit::query()->where('status', 'active')->where(fn ($query) => $query
                ->where('measurement_type_id', $measurement_type_id)
                ->orWhereHas('measurementType', fn ($type) => $type->where('code', \App\Models\MeasurementType::COUNT)))
                ->orderBy('name')->get()
            : collect();
        $unitConversionRows = is_iterable($unit_conversions ?? null)
            ? $unit_conversions
            : (isset($this) && is_iterable($this->unit_conversions ?? null) ? $this->unit_conversions : []);
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-black text-slate-900 dark:text-white">Unit Conversions &amp; Pricing</h3>
            <p class="mt-1 text-xs text-slate-500">Stock always remains in {{ $baseUnitForConversions?->short_name ?: 'the Base Stock Unit' }}. Package prices may differ from multiplied base prices.</p>
        </div>
        <button type="button" wire:click="addUnitConversion" @disabled(! $baseUnitForConversions) class="rounded-lg border border-cyan-300 bg-white px-3 py-2 text-xs font-black text-cyan-800 disabled:opacity-50 dark:bg-slate-900 dark:text-cyan-200">Add Alternative Unit</button>
    </div>

    <div class="mt-4 space-y-4">
        @forelse ($unitConversionRows as $index => $conversion)
            @php
                $alternativeUnit = filled($conversion['unit_id'] ?? null) ? \App\Models\Unit::find($conversion['unit_id']) : null;
                $alternativeUnitLabel = $alternativeUnit?->name;
                $baseUnitLabel = $baseUnitForConversions?->short_name ?: $baseUnitForConversions?->name ?: 'base units';
                $basePriceUnitLabel = $baseUnitForConversions?->short_name ? str($baseUnitForConversions->short_name)->singular()->toString() : 'base unit';
                $factor = (float) ($conversion['conversion_factor'] ?? 0);
                $canPurchase = (bool) ($conversion['can_purchase'] ?? false);
                $canSell = (bool) ($conversion['can_sell'] ?? false);
                $baseBuyingPrice = (float) ($buying_price ?? 0);
                $baseRetailPrice = (float) ($selling_price ?? 0);
                $baseWholesalePrice = (float) ($wholesale_price ?? 0);
                $purchaseEquivalent = $factor > 0 && $baseBuyingPrice > 0 ? $factor * $baseBuyingPrice : null;
                $retailEquivalent = $factor > 0 && $baseRetailPrice > 0 ? $factor * $baseRetailPrice : null;
                $wholesaleEquivalent = $factor > 0 && $baseWholesalePrice > 0 ? $factor * $baseWholesalePrice : null;
                $hasLargeMismatch = fn ($entered, $equivalent, bool $enabled): bool => $enabled
                    && filled($entered)
                    && $factor > 0
                    && $equivalent !== null
                    && ((float) $entered < $equivalent * 0.5 || (float) $entered > $equivalent * 2);
                $purchaseMismatch = $hasLargeMismatch($conversion['purchase_price'] ?? null, $purchaseEquivalent, $canPurchase);
                $retailMismatch = $hasLargeMismatch($conversion['retail_price'] ?? null, $retailEquivalent, $canSell);
                $wholesaleMismatch = $hasLargeMismatch($conversion['wholesale_price'] ?? null, $wholesaleEquivalent, $canSell);
            @endphp
            <div wire:key="unit-conversion-{{ $index }}" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-navy-950">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label class="text-xs font-bold">Unit
                        <select wire:model.live="unit_conversions.{{ $index }}.unit_id" class="mt-1 block w-full rounded-lg border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">Select unit</option>
                            @foreach ($conversionUnits as $availableUnit)
                                @if ((int) $availableUnit->id !== (int) $unit_id)<option value="{{ $availableUnit->id }}">{{ $availableUnit->name }} / {{ $availableUnit->short_name }}</option>@endif
                            @endforeach
                        </select>
                        @error("unit_conversions.{$index}.unit_id")<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <div class="text-xs font-bold" data-alternative-unit="{{ $alternativeUnitLabel ?: '' }}" data-base-unit="{{ $baseUnitLabel }}">
                        <p>{{ $alternativeUnitLabel ? $alternativeUnitLabel.' contains' : 'Contains' }}</p>
                        <label class="mt-1 flex items-center gap-2 text-sm font-black text-slate-800 dark:text-slate-100">
                            @if ($alternativeUnitLabel)<span class="whitespace-nowrap">1 {{ $alternativeUnitLabel }} =</span>@endif
                            <input aria-label="{{ $alternativeUnitLabel ? 'How many '.$baseUnitLabel.' are contained in 1 '.$alternativeUnitLabel : 'Contained base-unit quantity' }}" type="number" min="0.0001" step="0.0001" wire:model.live="unit_conversions.{{ $index }}.conversion_factor" class="block min-w-0 flex-1 rounded-lg border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <span class="whitespace-nowrap">{{ $baseUnitLabel }}</span>
                        </label>
                        <span class="mt-1 block text-[11px] font-medium text-slate-500">{{ $alternativeUnitLabel ? 'Enter how many '.$baseUnitLabel.' are contained in one '.$alternativeUnitLabel.'.' : 'Select a unit, then enter how many '.$baseUnitLabel.' it contains.' }}</span>
                        @error("unit_conversions.{$index}.conversion_factor")<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                    @if (auth()->user()->can('products.view_buying_price'))
                        <label data-price-field="purchase" data-enabled="{{ $canPurchase ? 'true' : 'false' }}" class="text-xs font-bold {{ $canPurchase ? '' : 'opacity-50' }}">Purchase Price{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_buying_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.purchase_price" @disabled(! $canPurchase) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['purchase_price'] ?? 0) }}</span>
                            @endif
                            @if ($purchaseEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">Base-cost equivalent: TZS {{ \App\Support\NumberFormatter::money($purchaseEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($purchaseMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">This means an effective cost of TZS {{ \App\Support\NumberFormatter::money((float) $conversion['purchase_price'] / $factor) }} / {{ $basePriceUnitLabel }}, while the Base Unit buying price is TZS {{ \App\Support\NumberFormatter::money($baseBuyingPrice) }} / {{ $basePriceUnitLabel }}. Please confirm.</span>@endif
                            @error("unit_conversions.{$index}.purchase_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                    @endif
                    @if (auth()->user()->can('products.view_selling_price'))
                        <label data-price-field="retail" data-enabled="{{ $canSell ? 'true' : 'false' }}" class="text-xs font-bold {{ $canSell ? '' : 'opacity-50' }}">Retail Price{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_selling_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.retail_price" @disabled(! $canSell) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['retail_price'] ?? 0) }}</span>
                            @endif
                            @if ($retailEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">Base-price equivalent: TZS {{ \App\Support\NumberFormatter::money($retailEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($retailMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">This means an effective retail price of TZS {{ \App\Support\NumberFormatter::money((float) $conversion['retail_price'] / $factor) }} / {{ $basePriceUnitLabel }}, while the Base Unit retail price is TZS {{ \App\Support\NumberFormatter::money($baseRetailPrice) }} / {{ $basePriceUnitLabel }}. Please confirm.</span>@endif
                            @error("unit_conversions.{$index}.retail_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                        <label data-price-field="wholesale" data-enabled="{{ $canSell ? 'true' : 'false' }}" class="text-xs font-bold {{ $canSell ? '' : 'opacity-50' }}">Wholesale Price{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_selling_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.wholesale_price" @disabled(! $canSell) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['wholesale_price'] ?? 0) }}</span>
                            @endif
                            @if ($wholesaleEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">Base-price equivalent: TZS {{ \App\Support\NumberFormatter::money($wholesaleEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($wholesaleMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">This means an effective wholesale price of TZS {{ \App\Support\NumberFormatter::money((float) $conversion['wholesale_price'] / $factor) }} / {{ $basePriceUnitLabel }}, while the Base Unit wholesale price is TZS {{ \App\Support\NumberFormatter::money($baseWholesalePrice) }} / {{ $basePriceUnitLabel }}. Please confirm.</span>@endif
                            @error("unit_conversions.{$index}.wholesale_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-5 text-xs font-bold">
                    <label title="Available on Purchase Orders and Receiving"><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.can_purchase" class="mr-2 rounded">Can Purchase</label>
                    <label title="Available in POS and Sales"><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.can_sell" class="mr-2 rounded">Can Sell</label>
                    <label><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.active" class="mr-2 rounded">Active</label>
                    <button type="button" wire:click="removeUnitConversion({{ $index }})" class="ml-auto font-black text-red-600">Remove</button>
                </div>
            </div>
        @empty
            <p class="rounded-lg border border-dashed border-slate-300 p-3 text-xs text-slate-500">No alternative units configured. Base-unit buying and selling continue to work as before.</p>
        @endforelse
    </div>
</section>
