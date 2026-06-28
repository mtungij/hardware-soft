<?php

use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'setting' => null,
    'enable_warehouse' => true,
    'allow_direct_stock_in' => true,
    'allow_sales_from_store' => false,
    'default_stock_location_id' => '',
    'has_stock_movements' => false,
]);

mount(function (InventoryService $inventory) {
    $branchId = InventorySettings::branchId();

    $this->setting = Setting::query()->first() ?: Setting::query()->create(['company_name' => config('app.name', 'Hardex POS')]);
    if ((bool) $this->setting->enable_warehouse) {
        $inventory->getMainStoreLocation($branchId);
    }
    $inventory->getDispensingLocation($branchId);

    $this->enable_warehouse = (bool) $this->setting->enable_warehouse;
    $this->allow_direct_stock_in = (bool) $this->setting->allow_direct_stock_in;
    $this->allow_sales_from_store = (bool) $this->setting->allow_sales_from_store;
    $this->default_stock_location_id = (string) $this->setting->default_stock_location_id;
    $this->has_stock_movements = StockMovement::query()->exists();
});

rules(fn () => [
    'enable_warehouse' => ['boolean'],
    'allow_direct_stock_in' => ['boolean'],
    'allow_sales_from_store' => ['boolean'],
    'default_stock_location_id' => ['nullable', Rule::exists('stock_locations', 'id')],
]);

$save = function () {
    $validated = $this->validate();

    if ($this->has_stock_movements && (bool) $validated['enable_warehouse'] !== (bool) $this->setting->enable_warehouse) {
        $this->addError('enable_warehouse', 'Huwezi kubadilisha mfumo wa stock baada ya kuanza kutumia stock movements. Tafadhali wasiliana na admin wa mfumo.');

        return;
    }

    if (! (bool) $validated['enable_warehouse']) {
        $validated['allow_sales_from_store'] = false;
        $validated['default_stock_location_id'] = app(InventoryService::class)->getDispensingLocation(InventorySettings::branchId())->id;
        StockLocation::query()
            ->where('branch_id', InventorySettings::branchId())
            ->where('type', 'store')
            ->update(['status' => 'inactive']);
    } else {
        $mainStore = app(InventoryService::class)->getMainStoreLocation(InventorySettings::branchId());
        $mainStore->update(['status' => 'active']);
        $validated['default_stock_location_id'] = $validated['default_stock_location_id'] ?: $mainStore->id;
    }

    $this->setting->update($validated);
    $this->default_stock_location_id = (string) $this->setting->refresh()->default_stock_location_id;
    $this->allow_sales_from_store = (bool) $this->setting->allow_sales_from_store;

    session()->flash('success', 'Inventory settings saved.');
};

?>

<div>
    <x-page-header
        title="Inventory Settings"
        description="Choose whether this business uses Main Store warehouse flow or direct Dispensing Area stock flow."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'Inventory' => null]"
    />

    <x-card title="Stock Mode" description="Warehouse mode uses Main Store, transfers, and Dispensing Area. Direct mode sends stock straight to Dispensing Area.">
        <form wire:submit="save" class="grid gap-5 lg:grid-cols-2">
            <div class="lg:col-span-2">
                <p class="text-sm font-black text-slate-900 dark:text-white">Je, hardware yako ina Ghala Kuu?</p>
                <p class="mt-1 text-sm text-slate-500">Chagua mfumo wa stock unaotumika kwenye biashara hii.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="cursor-pointer rounded-2xl border p-4 transition {{ $enable_warehouse ? 'border-cyan-500 bg-cyan-50 shadow-lg shadow-cyan-500/10 dark:bg-cyan-500/10' : 'border-slate-200 bg-white hover:border-cyan-300 dark:border-slate-800 dark:bg-slate-900' }} {{ $has_stock_movements ? 'cursor-not-allowed opacity-75' : '' }}">
                        <input type="radio" wire:model.live="enable_warehouse" value="1" class="sr-only" @disabled($has_stock_movements)>
                        <span class="flex items-start gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 21h18" />
                                    <path d="M5 21V8l7-4 7 4v13" />
                                    <path d="M9 21v-8h6v8" />
                                </svg>
                            </span>
                            <span>
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-black">Nina Ghala Kuu</span>
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">Inafaa kwa hardware kubwa</span>
                                </span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600 dark:text-slate-300">Mzigo utaingia Ghala Kuu kwanza, kisha utahamishwa kwenda sehemu ya mauzo.</span>
                            </span>
                        </span>
                    </label>

                    <label class="cursor-pointer rounded-2xl border p-4 transition {{ ! $enable_warehouse ? 'border-cyan-500 bg-cyan-50 shadow-lg shadow-cyan-500/10 dark:bg-cyan-500/10' : 'border-slate-200 bg-white hover:border-cyan-300 dark:border-slate-800 dark:bg-slate-900' }} {{ $has_stock_movements ? 'cursor-not-allowed opacity-75' : '' }}">
                        <input type="radio" wire:model.live="enable_warehouse" value="0" class="sr-only" @disabled($has_stock_movements)>
                        <span class="flex items-start gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 10h16" />
                                    <path d="M5 10l1-5h12l1 5" />
                                    <path d="M6 10v9h12v-9" />
                                    <path d="M9 19v-5h6v5" />
                                </svg>
                            </span>
                            <span>
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-black">Sina Ghala Kuu</span>
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-black text-amber-700 dark:bg-amber-500/15 dark:text-amber-200">Inafaa kwa hardware ndogo</span>
                                </span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600 dark:text-slate-300">Mzigo utaingia moja kwa moja sehemu ya mauzo na kuuzwa bila transfer.</span>
                            </span>
                        </span>
                    </label>
                </div>
                @if ($has_stock_movements)
                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        Huwezi kubadilisha mfumo wa stock baada ya kuanza kutumia stock movements. Tafadhali wasiliana na admin wa mfumo.
                    </p>
                @endif
                @error('enable_warehouse') <span class="mt-2 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </div>

            <label class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span class="block text-sm font-black">Default Stock Location</span>
                <select wire:model="default_stock_location_id" class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(! $enable_warehouse)>
                    @foreach (StockLocation::query()->where('branch_id', InventorySettings::branchId())->where('status', 'active')->orderBy('type')->get() as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span>
                    <span class="block text-sm font-black">Allow Direct Stock In</span>
                    <span class="mt-1 block text-xs text-slate-500">Let staff add stock without supplier or purchase order.</span>
                </span>
                <input type="checkbox" wire:model="allow_direct_stock_in" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800 {{ ! $enable_warehouse ? 'opacity-60' : '' }}">
                <span>
                    <span class="block text-sm font-black">Allow Sales From Main Store</span>
                    <span class="mt-1 block text-xs text-slate-500">Only applies when warehouse mode is enabled.</span>
                </span>
                <input type="checkbox" wire:model="allow_sales_from_store" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500" @disabled(! $enable_warehouse)>
            </label>

            <div class="lg:col-span-2 flex justify-end">
                <button class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20">Save Inventory Settings</button>
            </div>
        </form>
    </x-card>
</div>
