@php
    $basePricingUnit = filled($unit_id) ? \App\Models\Unit::find($unit_id) : null;
    $basePricingUnitLabel = $basePricingUnit?->short_name
        ? str($basePricingUnit->short_name)->singular()->toString()
        : 'base unit';
@endphp

<section class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-white/5 md:col-span-2 xl:col-span-3">
    <div>
        <h3 class="text-sm font-black uppercase tracking-wide text-slate-900 dark:text-white">{{ __('application.base_unit_pricing_321c93a7') }}</h3>
        <p class="mt-1 text-xs text-slate-500">{{ __('application.base_stock_unit_95dd55c7') }} <span class="font-black text-slate-700 dark:text-slate-200">{{ $basePricingUnit?->short_name ?: __('terminology.not_selected_183079f3') }}</span></p>
    </div>
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        @if (auth()->user()->can('products.view_buying_price'))
            @if (auth()->user()->can('products.edit_buying_price'))
                <x-money-input label="Buying Price / {{ $basePricingUnitLabel }}" name="buying_price" value="{{ $buying_price }}" wire:model.live="buying_price" required />
            @else
                <div><span class="text-xs text-slate-500">{{ __('application.buying_price_c4ae496e') }} {{ $basePricingUnitLabel }}</span><p class="mt-1 font-bold">TZS {{ \App\Support\NumberFormatter::money($buying_price) }}</p></div>
            @endif
        @endif
        @if (auth()->user()->can('products.view_selling_price'))
            @if (auth()->user()->can('products.edit_selling_price'))
                <x-money-input label="Retail Price / {{ $basePricingUnitLabel }}" name="selling_price" value="{{ $selling_price }}" wire:model.live="selling_price" required />
                <x-money-input label="Wholesale Price / {{ $basePricingUnitLabel }}" name="wholesale_price" value="{{ $wholesale_price }}" wire:model.live="wholesale_price" />
            @else
                <div><span class="text-xs text-slate-500">{{ __('application.retail_price_bbbcbc93') }} {{ $basePricingUnitLabel }}</span><p class="mt-1 font-bold">TZS {{ \App\Support\NumberFormatter::money($selling_price) }}</p></div>
                <div><span class="text-xs text-slate-500">{{ __('application.wholesale_price_83725a63') }} {{ $basePricingUnitLabel }}</span><p class="mt-1 font-bold">TZS {{ \App\Support\NumberFormatter::money($wholesale_price) }}</p></div>
            @endif
        @endif
    </div>
</section>
