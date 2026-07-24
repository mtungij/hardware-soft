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
    session()->flash('success', 'Unit saved successfully.');
};

$toggleStatus = function (int $unitId) {
    abort_unless($this->canManage(), 403);

    $unit = Unit::findOrFail($unitId);
    $unit->update(['status' => $unit->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Unit status updated.');
};

$deleteUnit = function (int $unitId) {
    abort_unless($this->canManage(), 403);

    $unit = Unit::withCount(['products', 'sellingProducts'])->findOrFail($unitId);

    if ($unit->products_count + $unit->selling_products_count > 0) {
        session()->flash('error', 'Cannot delete a unit with attached products.');
        return;
    }

    $unit->delete();
    session()->flash('success', 'Unit deleted.');
};

?>

<div>
    <x-page-header
        title="Units"
        description="Maintain company units and classify them for automatic Product Form filtering."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Units' => null]"
    >
        <x-export-actions export="tables.units" :params="compact('search', 'statusFilter', 'measurementTypeFilter')" />
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? 'Edit Unit' : 'Create Unit'" description="Short names are used on product labels and forms.">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="Unit Name" name="name" wire:model="name" required />
                    <x-form-input label="Short Name" name="short_name" wire:model="short_name" required />

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Measurement Type
                        <select wire:model="measurement_type_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Select measurement type</option>
                            @foreach (MeasurementType::orderBy('sort_order')->get() as $measurementType)
                                <option value="{{ $measurementType->id }}">{{ $measurementType->name }}</option>
                            @endforeach
                        </select>
                        @error('measurement_type_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                    </label>

                    @if ($editingId)
                        @php
                            $editingUnit = Unit::query()
                                ->withCount(['products', 'sellingProducts'])
                                ->find($editingId);
                        @endphp
                        @if (($editingUnit?->products_count ?? 0) + ($editingUnit?->selling_products_count ?? 0) > 0)
                            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
                                This unit is assigned to products. Its Measurement Type can only be changed when all assigned products remain compatible.
                            </div>
                        @endif
                    @endif

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Status
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Description
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Unit</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Clear</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="Units List" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search units...">
                <select wire:model.live="measurementTypeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All measurement types</option>
                    @foreach (MeasurementType::orderBy('sort_order')->get() as $measurementType)
                        <option value="{{ $measurementType->id }}">{{ $measurementType->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            @php
                $units = Unit::query()
                    ->with('measurementType')
                    ->withCount(['products', 'sellingProducts'])
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%")))
                    ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                    ->when($measurementTypeFilter, fn ($query) => $query->where('measurement_type_id', $measurementTypeFilter))
                    ->latest()
                    ->paginate(10);
            @endphp

            <x-table :headers="['Name', 'Short Name', 'Measurement Type', 'Description', 'Products', 'Status', 'Actions']">
                @forelse ($units as $unit)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $unit->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $unit->short_name }}</td>
                        <td class="px-4 py-3 font-bold">{{ $unit->measurementType?->name ?? 'Other' }}</td>
                        <td class="px-4 py-3">{{ $unit->description ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $unit->products_count + $unit->selling_products_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $unit->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($unit->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editUnit({{ $unit->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</button>
                                    <button wire:click="toggleStatus({{ $unit->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $unit->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    <button wire:click="deleteUnit({{ $unit->id }})" wire:confirm="Delete this unit?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No units found.</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $units->links() }}</div>
        </x-card>
    </div>
</div>
