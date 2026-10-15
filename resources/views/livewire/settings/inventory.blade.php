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
    'inventory_mode' => 'multi_location',
    'allow_multiple_dispensing_locations' => false,
    'allow_credit_sale_without_customer' => true,
    'default_stock_location_id' => '',
    'has_stock_movements' => false,
]);

mount(function (InventoryService $inventory) {
    $branchId = InventorySettings::branchId();

    $this->setting = Setting::query()->first() ?: Setting::query()->create(['company_name' => config('app.name', 'Hardex POS')]);
    if ((bool) $this->setting->enable_warehouse) {
        $inventory->getMainStoreLocation($branchId);
    }
    if (! (bool) $this->setting->enable_warehouse || $inventory->getDispensingLocations($branchId)->isEmpty()) {
        $inventory->getDispensingLocation($branchId);
    }

    $this->enable_warehouse = (bool) $this->setting->enable_warehouse;
    $this->allow_direct_stock_in = (bool) $this->setting->allow_direct_stock_in;
    $this->allow_sales_from_store = (bool) $this->setting->allow_sales_from_store;
    $this->inventory_mode = $this->setting->inventory_mode ?: ((bool) $this->setting->enable_warehouse ? 'multi_location' : 'single_location');
    $this->allow_multiple_dispensing_locations = (bool) $this->setting->allow_multiple_dispensing_locations;
    $this->allow_credit_sale_without_customer = (bool) ($this->setting->allow_credit_sale_without_customer ?? true);
    $this->default_stock_location_id = (string) $this->setting->default_stock_location_id;
    $this->has_stock_movements = StockMovement::query()->exists();
});

rules(fn () => [
    'enable_warehouse' => ['boolean'],
    'allow_direct_stock_in' => ['boolean'],
    'allow_sales_from_store' => ['boolean'],
    'inventory_mode' => ['required', Rule::in(['single_location', 'multi_location'])],
    'allow_multiple_dispensing_locations' => ['boolean'],
    'allow_credit_sale_without_customer' => ['boolean'],
    'default_stock_location_id' => ['nullable', Rule::exists('stock_locations', 'id')],
]);

$updatedInventoryMode = function () {
    $this->enable_warehouse = $this->inventory_mode === 'multi_location';
};

$updatedEnableWarehouse = function () {
    $this->inventory_mode = $this->enable_warehouse ? 'multi_location' : 'single_location';
};

$save = function () {
    $validated = $this->validate();

    if ($this->has_stock_movements && (bool) $validated['enable_warehouse'] !== (bool) $this->setting->enable_warehouse) {
        $this->addError('enable_warehouse', __('application.you_cannot_change_stock_mode_after_stock_movements_have_started_please_cont_bb2bae8a'));

        return;
    }

    $validated['enable_warehouse'] = $validated['inventory_mode'] === 'multi_location';

    if (! (bool) $validated['enable_warehouse']) {
        $validated['allow_direct_stock_in'] = true;
        $validated['allow_sales_from_store'] = false;
        $validated['default_stock_location_id'] = app(InventoryService::class)->getDispensingLocation(InventorySettings::branchId())->id;
        StockLocation::query()
            ->where('branch_id', InventorySettings::branchId())
            ->where('type', 'store')
            ->update(['status' => 'inactive']);
    } else {
        $validated['allow_direct_stock_in'] = false;
        $mainStore = app(InventoryService::class)->getMainStoreLocation(InventorySettings::branchId());
        $mainStore->update(['status' => 'active']);
        $validated['default_stock_location_id'] = $validated['default_stock_location_id'] ?: $mainStore->id;
    }

    $this->setting->update($validated);
    $this->default_stock_location_id = (string) $this->setting->refresh()->default_stock_location_id;
    $this->allow_sales_from_store = (bool) $this->setting->allow_sales_from_store;
    $this->allow_direct_stock_in = (bool) $this->setting->allow_direct_stock_in;
    $this->inventory_mode = $this->setting->inventory_mode;
    $this->allow_multiple_dispensing_locations = (bool) $this->setting->allow_multiple_dispensing_locations;
    $this->allow_credit_sale_without_customer = (bool) ($this->setting->allow_credit_sale_without_customer ?? true);

    session()->flash('success', __('application.inventory_settings_saved_b34a74b8'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.inventory_settings_fee4fc5c') }}"
        description="{{ __('application.choose_whether_this_business_uses_main_store_warehouse_flow_or_direct_dispe_3a809a63') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'Inventory' => null]"
    />

    <x-card title="{{ __('application.stock_mode_bf023909') }}" description="{{ __('application.warehouse_mode_uses_main_store_transfers_and_dispensing_area_direct_mode_se_fa676e45') }}">
        <form wire:submit="save" class="grid gap-5 lg:grid-cols-2">
            <div class="lg:col-span-2">
                <p class="text-sm font-black text-slate-900 dark:text-white">{{ __('application.inventory_mode_31a1c5f1') }}</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="cursor-pointer rounded-xl border p-4 transition {{ $inventory_mode === 'single_location' ? 'border-cyan-500 bg-cyan-50 dark:bg-cyan-500/10' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' }} {{ $has_stock_movements ? 'cursor-not-allowed opacity-75' : '' }}">
                        <input type="radio" wire:model.live="inventory_mode" value="single_location" class="sr-only" @disabled($has_stock_movements)>
                        <span class="font-black">{{ __('application.single_stock_location_3f88f177') }}</span>
                        <span class="mt-2 block text-sm text-slate-500">{{ __('application.use_one_default_sales_location_usually_main_store_1fcf9f50') }}</span>
                    </label>
                    <label class="cursor-pointer rounded-xl border p-4 transition {{ $inventory_mode === 'multi_location' ? 'border-cyan-500 bg-cyan-50 dark:bg-cyan-500/10' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' }} {{ $has_stock_movements ? 'cursor-not-allowed opacity-75' : '' }}">
                        <input type="radio" wire:model.live="inventory_mode" value="multi_location" class="sr-only" @disabled($has_stock_movements)>
                        <span class="font-black">{{ __('application.multiple_stock_locations_ff48c51c') }}</span>
                        <span class="mt-2 block text-sm text-slate-500">{{ __('application.use_warehouse_stores_showrooms_and_dispensing_locations_203eaa36') }}</span>
                    </label>
                </div>
                @error('inventory_mode') <span class="mt-2 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="lg:col-span-2">
                <p class="text-sm font-black text-slate-900 dark:text-white">{{ __('application.does_your_hardware_business_have_a_main_warehouse_e74adbe0') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('application.choose_the_stock_mode_used_by_this_business_cc3944db') }}</p>
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
                                    <span class="font-black">{{ __('application.i_have_a_main_warehouse_fdf80f6e') }}</span>
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">{{ __('application.suitable_for_large_hardware_businesses_7ae985be') }}</span>
                                </span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600 dark:text-slate-300">{{ __('application.goods_enter_the_main_warehouse_first_then_transfer_to_the_selling_area_5e614a54') }}</span>
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
                                    <span class="font-black">{{ __('application.i_have_no_main_warehouse_1a100f05') }}</span>
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-black text-amber-700 dark:bg-amber-500/15 dark:text-amber-200">{{ __('application.suitable_for_small_hardware_businesses_16c08a56') }}</span>
                                </span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600 dark:text-slate-300">{{ __('application.goods_enter_the_selling_area_directly_and_are_sold_without_a_transfer_b7a36a8a') }}</span>
                            </span>
                        </span>
                    </label>
                </div>
                @if ($has_stock_movements)
                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ __('application.you_cannot_change_stock_mode_after_stock_movements_have_started_please_cont_bb2bae8a') }}
                    </p>
                @endif
                @error('enable_warehouse') <span class="mt-2 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </div>

            <label class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span class="block text-sm font-black">{{ __('application.default_stock_location_7fb27249') }}</span>
                <select wire:model="default_stock_location_id" class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(! $enable_warehouse)>
                    @foreach (StockLocation::query()->where('branch_id', InventorySettings::branchId())->where('status', 'active')->orderBy('type')->get() as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span>
                    <span class="block text-sm font-black">{{ __('application.allow_direct_stock_in_d3327bea') }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ $enable_warehouse ? __('terminology.disabled_in_warehouse_mode_use_purchases_and_receiving_d3469a2a') : __('terminology.required_in_direct_mode_stock_goes_straight_to_dispensing_area_df8f919d') }}</span>
                </span>
                <input type="checkbox" wire:model="allow_direct_stock_in" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500" disabled>
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800 {{ ! $enable_warehouse ? 'opacity-60' : '' }}">
                <span>
                    <span class="block text-sm font-black">{{ __('application.allow_sales_from_main_store_b1e44e44') }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ __('application.only_applies_when_warehouse_mode_is_enabled_90c3dfa4') }}</span>
                </span>
                <input type="checkbox" wire:model="allow_sales_from_store" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500" @disabled(! $enable_warehouse)>
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span>
                    <span class="block text-sm font-black">{{ __('application.allow_multiple_selling_dispensing_locations_5d7fd89f') }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ __('application.enable_this_when_one_branch_has_more_than_one_shop_counter_or_selling_area__f8c8b50d') }}</span>
                </span>
                <input type="checkbox" wire:model="allow_multiple_dispensing_locations" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <span>
                    <span class="block text-sm font-black">{{ __('application.allow_credit_sale_without_customer_4a26ee3f') }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ __('application.when_enabled_pos_can_record_credit_sales_under_the_protected_unassigned_cre_d1c50801') }}</span>
                </span>
                <input type="checkbox" wire:model="allow_credit_sale_without_customer" class="h-5 w-5 rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
            </label>

            <div class="lg:col-span-2 flex justify-end">
                <button class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20">{{ __('application.save_inventory_settings_787e3141') }}</button>
            </div>
        </form>
    </x-card>
</div>
