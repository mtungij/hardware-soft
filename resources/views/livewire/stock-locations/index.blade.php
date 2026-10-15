<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Services\StockLocationDefaultService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'editing_id' => null,
    'name' => '',
    'code' => '',
    'type' => 'store',
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'description' => '',
    'is_default' => false,
    'is_active' => true,
    'can_receive_stock' => true,
    'can_issue_stock' => true,
    'can_sell' => false,
    'can_transfer' => true,
    'can_transfer_to_dispensing' => true,
    'is_dispensing_location' => false,
    'is_warehouse' => false,
]);

$capabilityDefaultsForType = fn (string $type): array => match ($type) {
    'warehouse' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => false,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => true,
        'is_dispensing_location' => false,
        'is_warehouse' => true,
        'is_default' => false,
        'is_active' => true,
    ],
    'store' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => true,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    'dispensing' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'can_transfer' => false,
        'can_transfer_to_dispensing' => false,
        'is_dispensing_location' => true,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    'showroom' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => false,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    'branch_store' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => true,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    'returns', 'damaged', 'curing', 'quarantine' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => false,
        'can_transfer' => false,
        'can_transfer_to_dispensing' => false,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    'transit' => [
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => false,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => false,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
    default => [
        'can_receive_stock' => false,
        'can_issue_stock' => false,
        'can_sell' => false,
        'can_transfer' => false,
        'can_transfer_to_dispensing' => false,
        'is_dispensing_location' => false,
        'is_warehouse' => false,
        'is_default' => false,
        'is_active' => true,
    ],
};

$applyCapabilityDefaults = function (?string $type = null) {
    foreach ($this->capabilityDefaultsForType($type ?: $this->type) as $field => $value) {
        $this->{$field} = $value;
    }
};

$updatedType = function (string $value) {
    $this->applyCapabilityDefaults($value);
};

$resetForm = function () {
    $this->reset(['editing_id', 'name', 'code', 'description']);
    $this->type = 'store';
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('status', 'active')->value('id'));
    $this->applyCapabilityDefaults('store');
    $this->resetErrorBag();
};

$openCreate = function () {
    $this->resetForm();
    $this->dispatch('open-modal', 'stock-location-form');
};

$openEdit = function (int $id) {
    $location = StockLocation::findOrFail($id);

    $this->editing_id = $location->id;
    $this->name = $location->name;
    $this->code = $location->code;
    $this->type = $location->type;
    $this->branch_id = (string) $location->branch_id;
    $this->description = (string) $location->description;
    $this->is_default = (bool) $location->is_default;
    $this->is_active = (bool) $location->is_active;
    $this->can_receive_stock = (bool) $location->can_receive_stock;
    $this->can_issue_stock = (bool) $location->can_issue_stock;
    $this->can_sell = (bool) $location->can_sell;
    $this->can_transfer = (bool) $location->can_transfer;
    $this->can_transfer_to_dispensing = (bool) $location->can_transfer_to_dispensing;
    $this->is_dispensing_location = (bool) $location->is_dispensing_location;
    $this->is_warehouse = (bool) $location->is_warehouse;
    $this->resetErrorBag();
    $this->dispatch('open-modal', 'stock-location-form');
};

$save = function () {
    $companyId = auth()->user()?->company_id;
    $validated = $this->validate([
        'name' => ['required', 'string', 'max:255'],
        'code' => [
            'required',
            'string',
            'max:50',
            Rule::unique('stock_locations', 'code')
                ->where(fn ($query) => $query->where('company_id', $companyId))
                ->ignore($this->editing_id),
        ],
        'type' => ['required', Rule::in(array_keys(StockLocation::TYPES))],
        'branch_id' => ['nullable', 'exists:branches,id'],
        'description' => ['nullable', 'string', 'max:1000'],
        'is_default' => ['boolean'],
        'is_active' => ['boolean'],
        'can_receive_stock' => ['boolean'],
        'can_issue_stock' => ['boolean'],
        'can_sell' => ['boolean'],
        'can_transfer' => ['boolean'],
        'can_transfer_to_dispensing' => ['boolean'],
        'is_dispensing_location' => ['boolean'],
        'is_warehouse' => ['boolean'],
    ]);

    if ($validated['is_default'] && ! $validated['is_active']) {
        throw ValidationException::withMessages(['is_default' => __('application.inactive_location_cannot_be_default_7ce142d0')]);
    }

    $validated['is_dispensing_location'] = $validated['is_dispensing_location'] || $validated['type'] === 'dispensing';
    $validated['is_warehouse'] = $validated['is_warehouse'] || $validated['type'] === 'warehouse';

    if (($validated['is_dispensing_location'] || $validated['type'] === 'dispensing') && ! Setting::query()->value('allow_multiple_dispensing_locations')) {
        $exists = StockLocation::query()
            ->where('branch_id', $validated['branch_id'])
            ->where(fn ($query) => $query->where('type', 'dispensing')->orWhere('is_dispensing_location', true))
            ->when($this->editing_id, fn ($query) => $query->whereKeyNot($this->editing_id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['type' => __('application.only_one_dispensing_location_is_allowed_per_branch_84d7ce32')]);
        }
    }

    $payload = [
        ...$validated,
        'branch_id' => $validated['branch_id'] ?: null,
        'status' => $validated['is_active'] ? 'active' : 'inactive',
    ];

    app(StockLocationDefaultService::class)->save(
        [...$payload, 'company_id' => $companyId, ...(! $this->editing_id ? ['created_by' => auth()->id()] : [])],
        $this->editing_id ? StockLocation::findOrFail($this->editing_id) : null
    );

    $this->dispatch('close-modal', 'stock-location-form');
    session()->flash('success', __('application.stock_location_saved_2acc0e12'));
};

$toggleActive = function (int $id) {
    $location = StockLocation::findOrFail($id);

    if ($location->is_default && $location->isActive()) {
        session()->flash('error', __('application.default_location_cannot_be_deactivated_db05d643'));

        return;
    }

    $active = ! $location->isActive();
    $location->update(['is_active' => $active, 'status' => $active ? 'active' : 'inactive']);
    session()->flash('success', __('application.stock_location_status_updated_3f8bf1cd'));
};

$makeDefault = function (int $id) {
    $location = StockLocation::findOrFail($id);

    if (! $location->isActive()) {
        session()->flash('error', __('application.inactive_location_cannot_be_default_7ce142d0'));

        return;
    }

    app(StockLocationDefaultService::class)->makeDefault($location);
    session()->flash('success', __('application.default_stock_location_updated_dfe9c858'));
};

$metrics = function (StockLocation $location): array {
    $inventory = app(InventoryService::class);
    $products = Product::query()->where('status', 'active')->get(['id', 'buying_price']);
    $quantity = 0;
    $value = 0;

    foreach ($products as $product) {
        $stock = $inventory->getProductStock($product->id, $location->id, (int) $location->branch_id);
        $quantity += $stock;
        $value += $stock * $inventory->getAverageCost($product->id, $location->id, (int) $location->branch_id);
    }

    $todaySales = SaleItem::query()
        ->where('stock_location_id', $location->id)
        ->whereHas('sale', fn ($query) => $query->whereDate('sale_date', today())->where('status', 'completed'))
        ->sum('line_total');

    return [$quantity, $value, $todaySales];
};

?>

<div>
    <x-page-header title="{{ __('application.stock_locations_84a43507') }}" description="{{ __('application.manage_warehouses_stores_showrooms_and_dispensing_areas_22a7e9e7') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Locations' => null]">
        <button type="button" wire:click="openCreate" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.add_location_fff38c0f') }}</button>
    </x-page-header>

    <x-card>
        <div class="mb-4">
            <input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 sm:max-w-sm" placeholder="{{ __('application.search_locations_2d80e75c') }}">
        </div>

        @php
            $locations = StockLocation::query()
                ->with(['branch'])
                ->withCount('users')
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate(10);
        @endphp

        <x-table :headers="[__('application.location_d219c681'), __('application.branch_1627510b'), __('terminology.capabilities_ca09c54b'), __('application.qty_1e5ff9e5'), __('application.stock_value_c2267f33'), __('terminology.today_sales_f842ddff'), __('application.users_57f2b181'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($locations as $location)
                @php [$quantity, $value, $todaySales] = $this->metrics($location); @endphp
                <tr class="align-top hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $location->name }}</p><p class="text-xs text-slate-500">{{ $location->code }} / {{ StockLocation::TYPES[$location->type] ?? $location->type }}</p></td>
                    <td class="px-4 py-3">{{ $location->branch?->name ?? __('application.all_branches_25c23746') }}</td>
                    <td class="px-4 py-3 text-xs">
                        <div class="flex flex-wrap gap-1">
                            @foreach ([['Sell', $location->can_sell], ['Receive', $location->can_receive_stock], ['Transfer', $location->can_transfer], ['To Disp.', $location->can_transfer_to_dispensing]] as [$label, $enabled])
                                <span class="{{ $enabled ? 'badge-success' : 'badge-warning' }}">{{ $label }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">{{ \App\Support\NumberFormatter::quantity($quantity) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($value) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($todaySales) }}</td>
                    <td class="px-4 py-3">{{ $location->users_count }}</td>
                    <td class="px-4 py-3"><span class="{{ $location->isActive() ? 'badge-success' : 'badge-warning' }}">{{ $location->isActive() ? __('application.active_a733b809') : __('application.inactive_09af574c') }}</span>@if ($location->is_default)<span class="ml-1 badge-success">{{ __('application.default_808d7dca') }}</span>@endif</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="openEdit({{ $location->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</button>
                            <button type="button" wire:click="makeDefault({{ $location->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.default_808d7dca') }}</button>
                            <button type="button" wire:click="toggleActive({{ $location->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $location->isActive() ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_stock_locations_found_3a3ddfa5') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $locations->links() }}</div>
    </x-card>

    <x-modal name="stock-location-form" maxWidth="4xl">
        <form wire:submit="save" class="flex max-h-[calc(100vh-3rem)] flex-col">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="text-lg font-black">{{ $editing_id ? __('terminology.edit_stock_location_727cabf9') : __('terminology.add_stock_location_dabd034d') }}</h2>
            </div>
            <div class="grid gap-4 overflow-y-auto p-5 md:grid-cols-2">
                <x-form-input label="{{ __('application.location_name_266d74e3') }}" name="name" wire:model="name" required />
                <x-form-input label="{{ __('application.code_adac6937') }}" name="code" wire:model="code" required />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.type_3deb7456') }}
                    <select wire:model.live="type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (StockLocation::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('type') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.branch_1627510b') }}
                    <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                        <option value="">{{ __('application.all_branches_25c23746') }}</option>
                        @foreach (Branch::where('status', 'active')->orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">{{ __('application.description_55f8ebc8') }}
                    <textarea wire:model="description" class="mt-1 min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
                <div class="grid gap-3 md:col-span-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        'can_receive_stock' => 'Can Receive Stock',
                        'can_issue_stock' => 'Can Issue Stock',
                        'can_sell' => 'Can Sell',
                        'can_transfer' => 'Can Transfer',
                        'can_transfer_to_dispensing' => 'Transfer to Dispensing',
                        'is_dispensing_location' => 'Is Dispensing',
                        'is_warehouse' => 'Is Warehouse',
                        'is_default' => 'Is Default',
                        'is_active' => 'Is Active',
                    ] as $field => $label)
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">
                            <input type="checkbox" wire:model="{{ $field }}" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('is_default') <span class="text-sm font-semibold text-red-600">{{ $message }}</span> @enderror
                @error('code') <span class="text-sm font-semibold text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700">
                <button type="button" x-on:click="$dispatch('close-modal', 'stock-location-form')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</button>
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_location_7ef11632') }}</button>
            </div>
        </form>
    </x-modal>
</div>
