<?php

use App\Models\MeasurementType;
use App\Models\Unit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'statusFilter' => '',
    'measurementTypeFilter' => '',
    'editingId' => null,
    'name' => '',
    'short_name' => '',
    'measurement_type_id' => '',
    'description' => '',
    'status' => 'active',
]);

$unitCompanyId = fn () => $this->editingId
    ? Unit::query()->findOrFail($this->editingId)->company_id
    : auth()->user()->company_id;

rules(fn () => [
    'name' => [
        'required',
        'string',
        'max:255',
        Rule::unique('units', 'name')
            ->where(fn ($query) => $query->where('company_id', $this->unitCompanyId()))
            ->ignore($this->editingId),
    ],
    'short_name' => [
        'required',
        'string',
        'max:30',
        Rule::unique('units', 'short_name')
            ->where(fn ($query) => $query->where('company_id', $this->unitCompanyId()))
            ->ignore($this->editingId),
    ],
    'measurement_type_id' => ['required', 'exists:measurement_types,id'],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$resetForm = function () {
    $this->reset(['editingId', 'name', 'short_name', 'measurement_type_id', 'description']);
    $this->status = 'active';
};

$editUnit = function (int $unitId) {
    abort_unless($this->canManage(), 403);

    $unit = Unit::findOrFail($unitId);

    $this->editingId = $unit->id;
    $this->name = $unit->name;
    $this->short_name = $unit->short_name;
    $this->measurement_type_id = (string) $unit->measurement_type_id;
    $this->description = $unit->description;
    $this->status = $unit->status;
};

$save = function () {
    abort_unless($this->canManage(), 403);

    $validated = $this->validate();
    $unit = $this->editingId
        ? Unit::query()->with(['products.measurementType', 'sellingProducts.measurementType'])->findOrFail($this->editingId)
        : null;

    if ($unit && (int) $unit->measurement_type_id !== (int) $validated['measurement_type_id']) {
        $incompatibleProducts = $unit->products
            ->merge($unit->sellingProducts)
            ->unique('id')
            ->filter(fn ($product) => (int) $product->measurement_type_id !== (int) $validated['measurement_type_id']);

        if ($incompatibleProducts->isNotEmpty()) {
            $this->addError(
                'measurement_type_id',
                'This unit is assigned to '.$incompatibleProducts->take(3)->pluck('name')->join(', ').'. Change those products first.',
            );

            return;
        }
    }

    $code = $unit?->code ?: Str::of($validated['short_name'])
        ->lower()
        ->replace(['²', '³'], ['2', '3'])
        ->replaceMatches('/[^a-z0-9]+/', '')
        ->toString();
    $validated['code'] = $code ?: null;
    $validated['company_id'] = $unit?->company_id ?: auth()->user()->company_id;

    Unit::query()->updateOrCreate(['id' => $this->editingId], $validated);

    $this->resetForm();
    session()->flash('success', __('application.unit_saved_successfully_b7f7244e'));
};

$toggleStatus = function (int $unitId) {
    abort_unless($this->canManage(), 403);

    $unit = Unit::findOrFail($unitId);
    $unit->update(['status' => $unit->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.unit_status_updated_eedb0e42'));
};

$deleteUnit = function (int $unitId) {
    abort_unless($this->canManage(), 403);

    $unit = Unit::withCount(['products', 'sellingProducts', 'purchaseProducts', 'productUnitConversions'])->findOrFail($unitId);

    if ($unit->products_count + $unit->selling_products_count + $unit->purchase_products_count + $unit->product_unit_conversions_count > 0) {
        session()->flash('error', __('application.cannot_delete_a_unit_used_by_products_or_their_conversions_d0dda5d3'));
        return;
    }

    $unit->delete();
    session()->flash('success', __('application.unit_deleted_d2c699e9'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.units_12748281') }}"
        description="{{ __('application.maintain_company_units_and_classify_them_for_automatic_product_form_filteri_f686d7cc') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Units' => null]"
    >
        <x-export-actions export="tables.units" :params="compact('search', 'statusFilter', 'measurementTypeFilter')" />
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? __('application.edit_unit_96758778') : __('application.create_unit_df2268c2')" description="{{ __('application.short_names_are_used_on_product_labels_and_forms_1d64f7ba') }}">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="{{ __('application.unit_name_a20117bb') }}" name="name" wire:model="name" required />
                    <x-form-input label="{{ __('application.short_name_da4db97e') }}" name="short_name" wire:model="short_name" required />

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.measurement_type_bbc2cb53') }}
                        <select wire:model="measurement_type_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">{{ __('application.select_measurement_type_d334101a') }}</option>
                            @foreach (MeasurementType::orderBy('sort_order')->get() as $measurementType)
                                <option value="{{ $measurementType->id }}">{{ $measurementType->name }}</option>
                            @endforeach
                        </select>
                        @error('measurement_type_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                    </label>

                    @if ($editingId)
                        @php
                            $editingUnit = Unit::query()
                                ->withCount(['products', 'sellingProducts', 'purchaseProducts', 'productUnitConversions'])
                                ->find($editingId);
                        @endphp
                        @if (($editingUnit?->products_count ?? 0) + ($editingUnit?->selling_products_count ?? 0) + ($editingUnit?->purchase_products_count ?? 0) + ($editingUnit?->product_unit_conversions_count ?? 0) > 0)
                            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
                                {{ __('application.this_unit_is_assigned_to_products_its_measurement_type_can_only_be_changed__f6db9ad1') }}
                            </div>
                        @endif
                    @endif

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.status_bae7d5be') }}
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">{{ __('application.active_a733b809') }}</option>
                            <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.description_55f8ebc8') }}
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_unit_7182f016') }}</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.clear_719ea396') }}</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="{{ __('application.units_list_018d655d') }}" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_units_2c6e7184') }}">
                <select wire:model.live="measurementTypeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.all_measurement_types_e6b776cf') }}</option>
                    @foreach (MeasurementType::orderBy('sort_order')->get() as $measurementType)
                        <option value="{{ $measurementType->id }}">{{ $measurementType->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                    <option value="active">{{ __('application.active_a733b809') }}</option>
                    <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                </select>
            </div>

            @php
                $units = Unit::query()
                    ->with('measurementType')
                    ->withCount(['products', 'sellingProducts', 'purchaseProducts', 'productUnitConversions'])
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%")))
                    ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                    ->when($measurementTypeFilter, fn ($query) => $query->where('measurement_type_id', $measurementTypeFilter))
                    ->latest()
                    ->paginate(10);
            @endphp

            <x-table :headers="[__('application.name_709a2322'), __('application.short_name_da4db97e'), __('application.measurement_type_bbc2cb53'), __('application.description_55f8ebc8'), __('application.products_fe0a091f'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
                @forelse ($units as $unit)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $unit->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $unit->short_name }}</td>
                        <td class="px-4 py-3 font-bold">{{ $unit->measurementType?->name ?? __('application.other_6e6a6f20') }}</td>
                        <td class="px-4 py-3">{{ $unit->description ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $unit->products_count + $unit->selling_products_count + $unit->purchase_products_count + $unit->product_unit_conversions_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $unit->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($unit->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editUnit({{ $unit->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</button>
                                    <button wire:click="toggleStatus({{ $unit->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $unit->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                    <button wire:click="deleteUnit({{ $unit->id }})" wire:confirm="{{ __('application.delete_this_unit_e2259017') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_units_found_81728fea') }}</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $units->links() }}</div>
        </x-card>
    </div>
</div>
