@php
    $basePricingUnit = filled($unit_id) ? \App\Models\Unit::find($unit_id) : null;
    $basePricingUnitLabel = $basePricingUnit?->short_name
        ? str($basePricingUnit->short_name)->singular()->toString()
        : 'base unit';
@endphp

<section class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-white/5 md:col-span-2 xl:col-span-3">
    <div>
        <h3 class="text-sm font-black uppercase tracking-wide text-slate-900 dark:text-white">Base Unit Pricing</h3>
        <p class="mt-1 text-xs text-slate-500">Base Stock Unit: <span class="font-black text-slate-700 dark:text-slate-200">{{ $basePricingUnit?->short_name ?: 'Not selected' }}</span></p>
    </div>
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <x-money-input label="Buying Price / {{ $basePricingUnitLabel }}" name="buying_price" value="{{ $buying_price }}" wire:model.live="buying_price" required />
        <x-money-input label="Retail Price / {{ $basePricingUnitLabel }}" name="selling_price" value="{{ $selling_price }}" wire:model.live="selling_price" required />
        <x-money-input label="Wholesale Price / {{ $basePricingUnitLabel }}" name="wholesale_price" value="{{ $wholesale_price }}" wire:model.live="wholesale_price" />
    </div>
</section>
