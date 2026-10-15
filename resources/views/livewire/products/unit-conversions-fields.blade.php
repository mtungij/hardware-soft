<section class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/5 md:col-span-2 xl:col-span-3">
    @php
        $baseUnitForConversions = filled($unit_id) ? \App\Models\Unit::find($unit_id) : null;
        $conversionUnits = filled($measurement_type_id)
            ? \App\Models\Unit::query()->where('status', 'active')->orderBy('name')->get()
            : collect();
        $unitConversionRows = is_iterable($unit_conversions ?? null)
            ? $unit_conversions
            : (isset($this) && is_iterable($this->unit_conversions ?? null) ? $this->unit_conversions : []);
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('application.unit_conversions_amp_pricing_778bc81d') }}</h3>
            <p class="mt-1 text-xs text-slate-500">{{ __('application.stock_always_remains_in_6d992081') }} {{ $baseUnitForConversions?->short_name ?: 'the Base Stock Unit' }}{{ __('application.package_prices_may_differ_from_multiplied_base_prices_83832c8d') }}</p>
        </div>
        <button type="button" wire:click="addUnitConversion" @disabled(! $baseUnitForConversions) class="rounded-lg border border-cyan-300 bg-white px-3 py-2 text-xs font-black text-cyan-800 disabled:opacity-50 dark:bg-slate-900 dark:text-cyan-200">{{ __('application.add_alternative_unit_c39a7b6d') }}</button>
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
                    <label class="text-xs font-bold">{{ __('application.unit_f6b935ab') }}
                        <select wire:model.live="unit_conversions.{{ $index }}.unit_id" class="mt-1 block w-full rounded-lg border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">{{ __('application.select_unit_bc6df6de') }}</option>
                            @foreach ($conversionUnits as $availableUnit)
                                @if ((int) $availableUnit->id !== (int) $unit_id)<option value="{{ $availableUnit->id }}">{{ $availableUnit->name }} / {{ $availableUnit->short_name }}</option>@endif
                            @endforeach
                        </select>
                        @error("unit_conversions.{$index}.unit_id")<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <div class="text-xs font-bold" data-alternative-unit="{{ $alternativeUnitLabel ?: '' }}" data-base-unit="{{ $baseUnitLabel }}">
                        <p>{{ $alternativeUnitLabel ? $alternativeUnitLabel.' contains' : __('terminology.contains_33e15d00') }}</p>
                        <label class="mt-1 flex items-center gap-2 text-sm font-black text-slate-800 dark:text-slate-100">
                            @if ($alternativeUnitLabel)<span class="whitespace-nowrap">1 {{ $alternativeUnitLabel }} =</span>@endif
                            <input aria-label="{{ $alternativeUnitLabel ? __('terminology.how_many_8fe856ea').$baseUnitLabel.' are contained in 1 '.$alternativeUnitLabel : __('terminology.contained_base_unit_quantity_ff0812f6') }}" type="number" min="0.0001" step="0.0001" wire:model.live="unit_conversions.{{ $index }}.conversion_factor" class="block min-w-0 flex-1 rounded-lg border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <span class="whitespace-nowrap">{{ $baseUnitLabel }}</span>
                        </label>
                        <span class="mt-1 block text-[11px] font-medium text-slate-500">{{ $alternativeUnitLabel ? __('terminology.enter_how_many_a889c4dd').$baseUnitLabel.' are contained in one '.$alternativeUnitLabel.'.' : __('terminology.select_a_unit_then_enter_how_many_d9a87673').$baseUnitLabel.' it contains.' }}</span>
                        @error("unit_conversions.{$index}.conversion_factor")<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                    @if (auth()->user()->can('products.view_buying_price'))
                        <label data-price-field="purchase" data-enabled="{{ $canPurchase ? 'true' : 'false' }}" class="text-xs font-bold {{ $canPurchase ? '' : 'opacity-50' }}">{{ __('application.purchase_price_c36eef8a') }}{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_buying_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.purchase_price" @disabled(! $canPurchase) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['purchase_price'] ?? 0) }}</span>
                            @endif
                            @if ($purchaseEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">{{ __('application.base_cost_equivalent_tzs_6d33cda1') }} {{ \App\Support\NumberFormatter::money($purchaseEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($purchaseMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ __('application.this_means_an_effective_cost_of_tzs_adfe8491') }} {{ \App\Support\NumberFormatter::money((float) $conversion['purchase_price'] / $factor) }} / {{ $basePriceUnitLabel }}{{ __('application.while_the_base_unit_buying_price_is_tzs_908b2cfc') }} {{ \App\Support\NumberFormatter::money($baseBuyingPrice) }} / {{ $basePriceUnitLabel }}{{ __('application.please_confirm_7ba768bc') }}</span>@endif
                            @error("unit_conversions.{$index}.purchase_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                    @endif
                    @if (auth()->user()->can('products.view_selling_price'))
                        <label data-price-field="retail" data-enabled="{{ $canSell ? 'true' : 'false' }}" class="text-xs font-bold {{ $canSell ? '' : 'opacity-50' }}">{{ __('application.retail_price_d3e361ae') }}{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_selling_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.retail_price" @disabled(! $canSell) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['retail_price'] ?? 0) }}</span>
                            @endif
                            @if ($retailEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">{{ __('application.base_price_equivalent_tzs_06398a72') }} {{ \App\Support\NumberFormatter::money($retailEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($retailMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ __('application.this_means_an_effective_retail_price_of_tzs_44836f81') }} {{ \App\Support\NumberFormatter::money((float) $conversion['retail_price'] / $factor) }} / {{ $basePriceUnitLabel }}{{ __('application.while_the_base_unit_retail_price_is_tzs_a05a6f31') }} {{ \App\Support\NumberFormatter::money($baseRetailPrice) }} / {{ $basePriceUnitLabel }}{{ __('application.please_confirm_7ba768bc') }}</span>@endif
                            @error("unit_conversions.{$index}.retail_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                        <label data-price-field="wholesale" data-enabled="{{ $canSell ? 'true' : 'false' }}" class="text-xs font-bold {{ $canSell ? '' : 'opacity-50' }}">{{ __('application.wholesale_price_7ecac3d9') }}{{ $alternativeUnitLabel ? ' / '.$alternativeUnitLabel : '' }}
                            @if (auth()->user()->can('products.edit_selling_price'))
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="unit_conversions.{{ $index }}.wholesale_price" @disabled(! $canSell) class="mt-1 block w-full rounded-lg border-slate-200 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:disabled:bg-slate-800">
                            @else
                                <span class="mt-1 block">TZS {{ \App\Support\NumberFormatter::money($conversion['wholesale_price'] ?? 0) }}</span>
                            @endif
                            @if ($wholesaleEquivalent !== null && $alternativeUnitLabel)<span class="mt-1 block text-[11px] font-medium text-slate-500">{{ __('application.base_price_equivalent_tzs_06398a72') }} {{ \App\Support\NumberFormatter::money($wholesaleEquivalent) }} / {{ $alternativeUnitLabel }}</span>@endif
                            @if ($wholesaleMismatch)<span class="mt-1 block rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ __('application.this_means_an_effective_wholesale_price_of_tzs_8311e14c') }} {{ \App\Support\NumberFormatter::money((float) $conversion['wholesale_price'] / $factor) }} / {{ $basePriceUnitLabel }}{{ __('application.while_the_base_unit_wholesale_price_is_tzs_5c86738c') }} {{ \App\Support\NumberFormatter::money($baseWholesalePrice) }} / {{ $basePriceUnitLabel }}{{ __('application.please_confirm_7ba768bc') }}</span>@endif
                            @error("unit_conversions.{$index}.wholesale_price")<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-5 text-xs font-bold">
                    <label title="{{ __('application.available_on_purchase_orders_and_receiving_03654e0b') }}"><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.can_purchase" class="mr-2 rounded">{{ __('application.can_purchase_96311fbe') }}</label>
                    <label title="{{ __('application.available_in_pos_and_sales_f158ba4f') }}"><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.can_sell" class="mr-2 rounded">{{ __('application.can_sell_6a99d03e') }}</label>
                    <label><input type="checkbox" wire:model.live="unit_conversions.{{ $index }}.active" class="mr-2 rounded">{{ __('application.active_a733b809') }}</label>
                    <button type="button" wire:click="removeUnitConversion({{ $index }})" class="ml-auto font-black text-red-600">{{ __('application.remove_e963907d') }}</button>
                </div>
            </div>
        @empty
            <p class="rounded-lg border border-dashed border-slate-300 p-3 text-xs text-slate-500">{{ __('application.no_alternative_units_configured_base_unit_buying_and_selling_continue_to_wo_1156fa9d') }}</p>
        @endforelse
    </div>
</section>
