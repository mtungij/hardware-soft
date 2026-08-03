<?php

use App\Models\ProductionMachineAssignment;
use App\Models\ProductionRecipe;
use App\Models\StockLocation;
use App\Services\ProductionOrderService;
use App\Support\CompanyFeatures;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\Log;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['assignment_id' => '', 'planned_quantity' => '', 'raw_location_id' => '', 'output_location_id' => '', 'finished_location_id' => '', 'notes' => '']);

mount(function (): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.create_orders'), 403);
    $this->assignment_id = (string) request('assignment', '');
    if ($this->assignment_id && ($assignment = ProductionMachineAssignment::query()->forCurrentCompany()->find($this->assignment_id))) {
        $this->planned_quantity = (string) ($assignment->target_quantity ?: '');
        $branchId = $assignment->branch_id ?: $assignment->machine?->branch_id ?: auth()->user()?->branch_id;
        $default = $branchId ? InventorySettings::defaultLocation((int) $branchId) : null;
        $this->raw_location_id = (string) ($default?->id ?: '');
        $this->finished_location_id = (string) ($default?->id ?: '');
        $this->output_location_id = $assignment->product?->requires_curing
            ? (string) (StockLocation::query()->where('company_id', CompanyFeatures::companyId())->where('branch_id', $branchId)->curing()->where('is_active', true)->value('id') ?: '')
            : $this->finished_location_id;
    }
});

$save = function () {
    $assignment = ProductionMachineAssignment::query()->forCurrentCompany()->findOrFail($this->assignment_id);
    $order = app(ProductionOrderService::class)->createFromAssignment($assignment, [
        'planned_quantity' => $this->planned_quantity,
        'raw_material_stock_location_id' => $this->raw_location_id,
        'production_output_stock_location_id' => $this->output_location_id,
        'final_finished_goods_stock_location_id' => $this->finished_location_id,
        'notes' => $this->notes,
    ], auth()->user());
    session()->flash('success', 'Production order created with an immutable recipe snapshot.');
    return $this->redirectRoute('production.orders.show', $order, navigate: true);
};

?>
<div>
    <x-page-header :title="'Create Production Order'" description="Create a planned order from one retained daily machine assignment." :breadcrumbs="['Dashboard' => route('dashboard'), __('production.orders.title') => route('production.orders.index'), 'Create' => null]" />
    @php
        $assignmentQuery = ProductionMachineAssignment::query()->forCurrentCompany()
            ->with(['machine', 'mould', 'product', 'recipe'])
            ->eligibleForProductionOrder()
            ->orderByDesc('production_date')
            ->orderBy('id');
        $assignments = $assignmentQuery->get();
        Log::debug('Production Order Create assignments loaded', [
            'assignment_ids' => $assignments->pluck('id')->all(),
            'sql' => $assignmentQuery->toSql(),
            'bindings' => $assignmentQuery->getBindings(),
        ]);
        $selected = $assignment_id ? ProductionMachineAssignment::query()->forCurrentCompany()->with(['machine.currentMouldInstallation.mould', 'mould', 'product', 'recipe'])->find($assignment_id) : null;
        $branchId = $selected?->branch_id ?: $selected?->machine?->branch_id ?: auth()->user()?->branch_id;
        $locations = StockLocation::query()->where('company_id', CompanyFeatures::companyId())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->where('status', 'active')->where('is_active', true)->orderBy('name')->get();
        $sellableLocations = $locations->where('is_sellable', true)->where('can_receive_stock', true);
        $curingLocations = $locations->whereIn('type', ['curing', 'quarantine'])->where('is_sellable', false)->where('can_receive_stock', true);
        $activeRecipe = $selected?->recipe?->status === ProductionRecipe::STATUS_ACTIVE ? $selected->recipe : null;
    @endphp
    <x-card title="Order Plan">
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-bold md:col-span-2">Daily Assignment
                <select data-testid="production-order-assignment-select" wire:model.live="assignment_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select assignment</option>
                    @foreach ($assignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->production_date->format('d M Y') }} · {{ $assignment->machine?->name }} · {{ $assignment->product?->name }}</option>@endforeach
                </select>
                @error('assignment') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            @if ($selected)
                <div class="rounded-xl bg-slate-100 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Machine / Date</p><p class="font-black">{{ $selected->machine?->name }} · {{ $selected->production_date->format('d M Y') }}</p></div>
                <div class="rounded-xl bg-slate-100 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Installed / Assigned Mould</p><p class="font-black">{{ $selected->machine?->currentMouldInstallation?->mould?->name ?: 'No mould installed' }} · {{ $selected->mould?->name ?: 'No assigned mould' }}</p></div>
                <div class="rounded-xl bg-slate-100 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Product / Active Recipe</p><p class="font-black">{{ $selected->product?->name }} · {{ $activeRecipe ? $activeRecipe->name.' v'.($activeRecipe->version ?: '—') : 'No active recipe' }}</p></div>
            @endif
            <x-form-input label="Planned Quantity" name="planned_quantity" type="number" min="0.0001" step="0.0001" wire:model.blur="planned_quantity" />
            <div></div>
            <label class="block text-sm font-bold">Raw Material Stock Location
                <select wire:model="raw_location_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"><option value="">Select location</option>@foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                @error('raw_material_stock_location_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            @if ($selected?->product?->requires_curing)
                <label class="block text-sm font-bold">Curing Stock Location
                    <select wire:model="output_location_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"><option value="">Select curing yard</option>@foreach ($curingLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                    @error('production_output_stock_location_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="block text-sm font-bold">{{ $selected?->product?->requires_curing ? 'Finished Goods Release Location' : 'Finished Goods Stock Location' }}
                <select wire:model="finished_location_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"><option value="">Select sellable location</option>@foreach ($sellableLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                @error('final_finished_goods_stock_location_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block text-sm font-bold md:col-span-2">Notes<textarea wire:model.blur="notes" class="mt-1 block min-h-24 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea></label>
            <div class="md:col-span-2 flex justify-end"><button class="rounded-xl bg-build-orange px-5 py-2.5 text-sm font-black text-white" @disabled(! $activeRecipe)>Create Planned Order</button></div>
        </form>
    </x-card>
</div>
