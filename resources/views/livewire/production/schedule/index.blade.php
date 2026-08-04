<?php

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionRecipe;
use App\Services\ProductionScheduleService;
use App\Support\CompanyFeatures;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'selectedDate' => '',
    'branchFilter' => '',
    'machineStatusFilter' => '',
    'productFilter' => '',
    'editingId' => null,
    'viewingId' => null,
    'machine_id' => '',
    'product_id' => '',
    'production_recipe_id' => '',
    'branch_id' => '',
    'target_quantity' => '',
    'planned_start_time' => '',
    'planned_end_time' => '',
    'status' => ProductionMachineAssignment::STATUS_PLANNED,
    'notes' => '',
    'capacityWarning' => '',
    'availableProducts' => [],
    'availableRecipes' => [],
]);

mount(function (): void {
    abort_unless(
        CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.view'),
        403
    );
    $this->selectedDate = CompanyFeatures::localDate();
});

rules(fn () => [
    'selectedDate' => ['required', 'date_format:Y-m-d'],
    'machine_id' => ['required', 'integer'],
    'product_id' => ['required', 'integer'],
    'production_recipe_id' => ['required', 'integer'],
    'branch_id' => ['nullable', 'integer'],
    'target_quantity' => ['nullable', 'numeric', 'gt:0'],
    'planned_start_time' => ['nullable', 'date_format:H:i'],
    'planned_end_time' => ['nullable', 'date_format:H:i', 'after:planned_start_time'],
    'status' => ['required', Rule::in([
        ProductionMachineAssignment::STATUS_PLANNED,
        ProductionMachineAssignment::STATUS_CONFIRMED,
        ProductionMachineAssignment::STATUS_CANCELLED,
    ])],
    'notes' => ['nullable', 'string', 'max:2000'],
]);

$canManage = fn (): bool => auth()->user()?->can('production.manage_schedule') ?? false;

$mouldHasChanged = function (ProductionMachineAssignment $assignment): bool {
    $assignment->loadMissing('machine.currentMouldInstallation.mould');
    $currentInstallation = $assignment->machine?->currentMouldInstallation;

    return ! $currentInstallation?->mould
        || (int) $currentInstallation->production_mould_id !== (int) $assignment->production_mould_id
        || (int) $currentInstallation->id !== (int) $assignment->production_mould_installation_id
        || (int) $currentInstallation->mould->product_family_id !== (int) $assignment->product?->product_family_id;
};

$requiresReassignment = function (ProductionMachineAssignment $assignment): bool {
    return in_array($assignment->status, [ProductionMachineAssignment::STATUS_PLANNED, ProductionMachineAssignment::STATUS_CONFIRMED], true)
        && ! $assignment->immutableProductionOrder()
        && $this->mouldHasChanged($assignment);
};

$resetForm = function (): void {
    $this->reset([
        'editingId', 'machine_id', 'product_id', 'production_recipe_id', 'branch_id', 'target_quantity',
        'planned_start_time', 'planned_end_time', 'notes', 'capacityWarning',
    ]);
    $this->availableProducts = [];
    $this->availableRecipes = [];
    $this->status = ProductionMachineAssignment::STATUS_PLANNED;
    $this->resetValidation();
};

$loadProductsForMachine = function ($machineId): void {
    $machine = filled($machineId)
        ? Machine::query()->forCurrentCompany()->whereKey($machineId)->first()
        : null;
    $installation = $machine?->currentMouldInstallation()->with('mould.family')->first();
    $familyId = $installation?->mould?->product_family_id;
    $siteId = filled($this->branch_id) ? (int) $this->branch_id : $machine?->branch_id;

    if (! $familyId) {
        $this->availableProducts = [];

        return;
    }

    $this->availableProducts = Product::query()
        ->where('company_id', CompanyFeatures::companyId())
        ->manufactured()
        ->where('product_family_id', $familyId)
        ->where('status', 'active')
        ->where(function ($query) use ($siteId): void {
            $query->whereNull('branch_id');

            if ($siteId !== null) {
                $query->orWhere('branch_id', $siteId);
            }
        })
        ->orderBy('name')
        ->get(['id', 'name'])
        ->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
        ])
        ->all();
};

$loadRecipesForProduct = function ($productId): void {
    $this->availableRecipes = filled($productId)
        ? ProductionRecipe::query()->forCurrentCompany()
            ->where('product_id', $productId)
            ->where('status', ProductionRecipe::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'version'])
            ->map(fn (ProductionRecipe $recipe) => ['id' => $recipe->id, 'name' => $recipe->name, 'version' => $recipe->version])
            ->all()
        : [];
};

$updatedMachineId = function (): void {
    $this->product_id = '';
    $this->production_recipe_id = '';
    $this->availableRecipes = [];
    $this->loadProductsForMachine($this->machine_id);
};

$updatedProductId = function (): void {
    $this->loadRecipesForProduct($this->product_id);
    $this->production_recipe_id = (string) ($this->availableRecipes[0]['id'] ?? '');
};

$updatedBranchId = function (): void {
    $selectedProductId = filled($this->product_id) ? (int) $this->product_id : null;
    $this->loadProductsForMachine($this->machine_id);

    if ($selectedProductId && ! collect($this->availableProducts)->contains('id', $selectedProductId)) {
        $this->product_id = '';
        $this->production_recipe_id = '';
        $this->availableRecipes = [];
    }
};

$refreshProductOptions = function (): void {
    $this->product_id = '';
    $this->production_recipe_id = '';
    $this->availableRecipes = [];
    $this->loadProductsForMachine($this->machine_id);
};

$changeDay = function (int $days): void {
    $this->selectedDate = Carbon::parse($this->selectedDate ?: CompanyFeatures::localDate())
        ->addDays($days)->toDateString();
    $this->resetPage();
    $this->resetForm();
};

$goToday = function (): void {
    $this->selectedDate = CompanyFeatures::localDate();
    $this->resetPage();
    $this->resetForm();
};

$save = function (): void {
    abort_unless($this->canManage(), 403);
    $validated = $this->validate();
    $validated['production_date'] = $validated['selectedDate'];
    unset($validated['selectedDate']);
    $validated['branch_id'] = filled($validated['branch_id']) ? $validated['branch_id'] : null;
    $validated['target_quantity'] = filled($validated['target_quantity']) ? $validated['target_quantity'] : null;
    $validated['planned_start_time'] = filled($validated['planned_start_time']) ? $validated['planned_start_time'] : null;
    $validated['planned_end_time'] = filled($validated['planned_end_time']) ? $validated['planned_end_time'] : null;

    $assignment = $this->editingId
        ? ProductionMachineAssignment::query()->forCurrentCompany()->findOrFail($this->editingId)
        : null;

    $machine = Machine::query()->forCurrentCompany()->with('currentMouldInstallation.mould')->find($validated['machine_id']);
    $this->capacityWarning = '';

    if (
        ($machine?->currentMouldInstallation?->mould?->expected_output_per_day !== null || $machine?->daily_capacity !== null)
        && $validated['target_quantity'] !== null
        && (float) $validated['target_quantity'] > (float) ($machine->currentMouldInstallation?->mould?->expected_output_per_day ?? $machine->daily_capacity)
    ) {
        $capacity = $machine->currentMouldInstallation?->mould?->expected_output_per_day ?? $machine->daily_capacity;
        $this->capacityWarning = "Target exceeds {$machine->name}'s installed-mould daily output of ".number_format((float) $capacity, 2).'.';
    }

    app(ProductionScheduleService::class)->save($validated, auth()->user(), $assignment);
    $warning = $this->capacityWarning;
    $this->resetForm();
    session()->flash('success', 'Daily machine assignment saved.');
    if ($warning) {
        session()->flash('warning', $warning);
    }
};

$editAssignment = function (int $assignmentId): void {
    abort_unless($this->canManage(), 403);
    $assignment = ProductionMachineAssignment::query()->forCurrentCompany()
        ->with(['product', 'productionOrder'])->findOrFail($assignmentId);

    if ($assignment->immutableProductionOrder()) {
        $this->addError('status', 'This historical assignment is linked to a production order and cannot be changed. Create a new assignment.');
        return;
    }

    if ($assignment->status === ProductionMachineAssignment::STATUS_COMPLETED) {
        $this->addError('status', __('production.validation.completed_read_only'));
        return;
    }

    $this->editingId = $assignment->id;
    $this->selectedDate = $assignment->production_date->toDateString();
    $this->machine_id = (string) $assignment->machine_id;
    $this->branch_id = $assignment->branch_id ? (string) $assignment->branch_id : '';
    $machine = Machine::query()->forCurrentCompany()->whereKey($assignment->machine_id)->firstOrFail();
    $currentInstallation = $machine->currentMouldInstallation()->with('mould.family')->first();
    $currentFamilyId = $currentInstallation?->mould?->product_family_id;
    $productMatchesCurrentFamily = $assignment->product
        && $currentFamilyId
        && (int) $assignment->product->product_family_id === (int) $currentFamilyId;
    $this->product_id = $productMatchesCurrentFamily ? (string) $assignment->product_id : '';
    $this->production_recipe_id = '';
    $this->loadProductsForMachine($this->machine_id);
    $this->loadRecipesForProduct($this->product_id);
    $this->target_quantity = $assignment->target_quantity ?? '';
    $this->planned_start_time = $assignment->planned_start_time ? substr($assignment->planned_start_time, 0, 5) : '';
    $this->planned_end_time = $assignment->planned_end_time ? substr($assignment->planned_end_time, 0, 5) : '';
    $this->status = $assignment->status;
    $this->notes = $assignment->notes ?? '';
};

$createNewAssignmentFrom = function (int $assignmentId): void {
    abort_unless($this->canManage(), 403);
    $assignment = ProductionMachineAssignment::query()->forCurrentCompany()
        ->with('productionOrder')->findOrFail($assignmentId);
    $isCancelled = $assignment->status === ProductionMachineAssignment::STATUS_CANCELLED;
    abort_unless($isCancelled || $assignment->historicalProductionOrder(), 422);

    $newDate = $isCancelled
        ? $assignment->production_date->copy()
        : $assignment->production_date->copy()->addDay();
    while (ProductionMachineAssignment::query()->forCurrentCompany()
        ->blocking()->where('machine_id', $assignment->machine_id)->whereDate('production_date', $newDate)->exists()) {
        $newDate->addDay();
    }
    $machine = Machine::query()->forCurrentCompany()->whereKey($assignment->machine_id)->firstOrFail();
    $installation = $machine->currentMouldInstallation()->with('mould')->first();
    $capacity = $installation?->mould?->expected_output_per_day ?? $machine->daily_capacity;
    $targetIsAppropriate = $assignment->target_quantity !== null
        && ($capacity === null || (float) $assignment->target_quantity <= (float) $capacity);

    $this->resetForm();
    $this->selectedDate = $newDate->toDateString();
    $this->machine_id = (string) $assignment->machine_id;
    $this->branch_id = $assignment->branch_id ? (string) $assignment->branch_id : '';
    $this->target_quantity = $targetIsAppropriate ? $assignment->target_quantity : '';
    $this->planned_start_time = $assignment->planned_start_time ? substr($assignment->planned_start_time, 0, 5) : '';
    $this->planned_end_time = $assignment->planned_end_time ? substr($assignment->planned_end_time, 0, 5) : '';
    $this->loadProductsForMachine($this->machine_id);
    session()->flash('warning', $isCancelled
        ? 'Replacement prepared on the cancelled assignment date. Select a current compatible product and active recipe, then save a new assignment.'
        : 'New assignment prepared from historical schedule. Select a current compatible product and active recipe, then save.');
};

$viewAssignment = fn (int $assignmentId) => $this->viewingId = ProductionMachineAssignment::query()
    ->forCurrentCompany()->findOrFail($assignmentId)->id;

$setAssignmentStatus = function (int $assignmentId, string $status): void {
    abort_unless($this->canManage(), 403);
    abort_unless(in_array($status, ProductionMachineAssignment::STATUSES, true), 422);
    $assignment = ProductionMachineAssignment::query()->forCurrentCompany()->with('productionOrder')->findOrFail($assignmentId);

    if ($assignment->immutableProductionOrder()) {
        $this->addError('status', 'This historical assignment is linked to a production order and cannot be changed. Create a new assignment.');
        return;
    }

    if ($assignment->status === ProductionMachineAssignment::STATUS_COMPLETED) {
        $this->addError('status', __('production.validation.completed_read_only'));
        return;
    }

    $allowedTransitions = [
        ProductionMachineAssignment::STATUS_PLANNED => [
            ProductionMachineAssignment::STATUS_CONFIRMED,
            ProductionMachineAssignment::STATUS_CANCELLED,
        ],
        ProductionMachineAssignment::STATUS_CONFIRMED => [
            ProductionMachineAssignment::STATUS_CANCELLED,
            ProductionMachineAssignment::STATUS_COMPLETED,
        ],
        ProductionMachineAssignment::STATUS_CANCELLED => [
            ProductionMachineAssignment::STATUS_PLANNED,
            ProductionMachineAssignment::STATUS_CONFIRMED,
        ],
    ];

    abort_unless(in_array($status, $allowedTransitions[$assignment->status] ?? [], true), 422);

    if ($assignment->status === ProductionMachineAssignment::STATUS_CANCELLED
        && in_array($status, ProductionMachineAssignment::BLOCKING_STATUSES, true)) {
        app(ProductionScheduleService::class)->save([
            'machine_id' => $assignment->machine_id,
            'product_id' => $assignment->product_id,
            'production_recipe_id' => $assignment->production_recipe_id,
            'branch_id' => $assignment->branch_id,
            'production_date' => $assignment->production_date->toDateString(),
            'target_quantity' => $assignment->target_quantity,
            'planned_start_time' => $assignment->planned_start_time,
            'planned_end_time' => $assignment->planned_end_time,
            'status' => $status,
            'notes' => $assignment->notes,
        ], auth()->user(), $assignment);
    } else {
        $assignment->update(['status' => $status, 'updated_by' => auth()->id()]);
    }
    session()->flash('success', $status === ProductionMachineAssignment::STATUS_COMPLETED
        ? 'Schedule marked completed. No inventory was created or consumed.'
        : 'Assignment status updated.');
};

?>

<div x-on:mould-installation-updated.window="$wire.refreshProductOptions()">
    <x-page-header
        :title="__('production.daily_schedule')"
        description="Plan one manufactured product per machine per day. This schedule does not move stock."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.daily_schedule') => null]"
    />

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif
    @if (session('warning')) <div class="mb-4 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-700">{{ session('warning') }}</div> @endif

    <x-card class="mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <button wire:click="changeDay(-1)" class="rounded-lg border px-3 py-2 font-bold">← Previous</button>
            <input type="date" wire:model.live="selectedDate" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
            <button wire:click="goToday" class="rounded-lg bg-navy-900 px-3 py-2 font-bold text-white">Today</button>
            <button wire:click="changeDay(1)" class="rounded-lg border px-3 py-2 font-bold">Next →</button>
        </div>
    </x-card>

    <div class="grid gap-6 {{ $this->canManage() ? 'xl:grid-cols-[400px_1fr]' : '' }}">
        @if ($this->canManage())
            <x-card :title="$editingId ? 'Edit / Reactivate Assignment' : 'Create Assignment'">
                <form wire:submit="save" class="space-y-4">
                    @php
                        $machines = Machine::query()->forCurrentCompany()->active()->orderBy('name')->get();
                        $selectedMachine = filled($machine_id)
                            ? Machine::query()->forCurrentCompany()->whereKey($machine_id)->first()
                            : null;
                        $selectedInstallation = $selectedMachine?->currentMouldInstallation()->with('mould.family')->first();
                        $installedMould = $selectedInstallation?->mould;
                        $existingAssignment = $selectedMachine && filled($selectedDate)
                            ? ProductionMachineAssignment::query()->forCurrentCompany()
                                ->blocking()
                                ->where('machine_id', $selectedMachine->id)
                                ->whereDate('production_date', $selectedDate)
                                ->when($editingId, fn ($query) => $query->whereKeyNot($editingId))
                                ->with(['product', 'productionOrder'])
                                ->first()
                            : null;
                    @endphp
                    <label class="block text-sm font-bold">Machine
                        <select data-testid="assignment-machine-select" wire:model.live="machine_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Select machine</option>
                            @foreach ($machines as $machine)
                                <option value="{{ $machine->id }}">{{ $machine->name }}{{ $machine->code ? " ({$machine->code})" : '' }}</option>
                            @endforeach
                        </select>
                        @error('machine_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    @if($existingAssignment)
                        @php
                            $existingOrder = $existingAssignment->immutableProductionOrder();
                        @endphp
                        <div data-testid="existing-assignment-notice" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
                            <p class="font-black">{{ $existingOrder ? 'Historical assignment.' : 'Assignment already exists.' }}</p>
                            <p class="mt-1">{{ $existingAssignment->product?->name ?: __('production.unknown_product') }} · {{ ucfirst($existingAssignment->status) }}</p>
                            @if($existingAssignment->historicalProductionOrder())
                                <p class="mt-1">Linked to completed Production Order {{ $existingOrder->order_number }}. Create a new assignment.</p>
                                <button type="button" wire:click="createNewAssignmentFrom({{ $existingAssignment->id }})" class="mt-2 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-black text-amber-900 dark:bg-slate-900 dark:text-amber-100">Create New Assignment From This</button>
                            @elseif(! $existingOrder && $existingAssignment->status !== ProductionMachineAssignment::STATUS_COMPLETED)
                                <button type="button" wire:click="editAssignment({{ $existingAssignment->id }})" class="mt-2 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-black text-amber-900 dark:bg-slate-900 dark:text-amber-100">Edit existing assignment</button>
                            @endif
                        </div>
                    @endif
                    <dl data-testid="assignment-current-installation" data-installation-id="{{ $selectedInstallation?->id }}" class="grid gap-3 rounded-xl border p-3 text-sm {{ $installedMould ? 'border-cyan-200 bg-cyan-50 dark:border-cyan-500/30 dark:bg-cyan-500/10' : 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' }}">
                        <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-300">Installed Mould</dt><dd class="mt-1 font-black text-slate-950 dark:text-white">{{ $installedMould?->name ?: __('production.moulds.none_installed') }}</dd></div>
                        @if($installedMould)
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-300">Family</dt><dd class="font-bold text-slate-900 dark:text-slate-100">{{ $installedMould->family?->name ?: '—' }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-300">Installation Date</dt><dd class="font-bold text-slate-900 dark:text-slate-100">{{ $selectedInstallation?->installed_at?->format('d M Y H:i') ?: '—' }}</dd></div>
                        @endif
                    </dl>
                    <label class="block text-sm font-bold">Manufactured Product
                        <select data-testid="assignment-product-select" wire:model.live="product_id" @disabled(! $installedMould) class="mt-1 block w-full rounded-lg border-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-navy-950 dark:disabled:bg-slate-800">
                            <option value="">Select product</option>
                            @foreach ($availableProducts as $product)
                                <option value="{{ $product['id'] }}">{{ $product['name'] }}</option>
                            @endforeach
                        </select>
                        @error('product_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm font-bold">Recipe
                        <select data-testid="assignment-recipe-select" wire:model.live="production_recipe_id" @disabled(! $installedMould || ! $product_id) class="mt-1 block w-full rounded-lg border-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-navy-950 dark:disabled:bg-slate-800">
                            <option value="">Select active recipe</option>
                            @foreach($availableRecipes as $recipe)<option value="{{ $recipe['id'] }}">{{ $recipe['name'] }} · {{ $recipe['version'] ?: '—' }}</option>@endforeach
                        </select>
                        @error('production_recipe_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-sm font-bold">Branch / Production Site
                        <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Company-wide</option>
                            @foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <x-form-input label="Target Quantity" name="target_quantity" type="number" min="0.0001" step="0.0001" wire:model="target_quantity" />
                    <div class="grid grid-cols-2 gap-3">
                        <x-form-input label="Start Time" name="planned_start_time" type="time" wire:model="planned_start_time" />
                        <x-form-input label="End Time" name="planned_end_time" type="time" wire:model="planned_end_time" />
                    </div>
                    <label class="block text-sm font-bold">Status
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="planned">Planned</option><option value="confirmed">Confirmed</option><option value="cancelled">Cancelled</option>
                        </select>
                    </label>
                    <label class="block text-sm font-bold">Notes
                        <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    @error('status') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Assignment</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border px-4 py-2.5 text-sm font-black">Clear</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="Schedule">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All branches</option>
                    @foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                </select>
                <select wire:model.live="machineStatusFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All machine statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="maintenance">Maintenance</option>
                </select>
                <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All products</option>
                    @foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </select>
            </div>
            @php
                $scheduleQuery = ProductionMachineAssignment::query()->forCurrentCompany()
                    ->with(['machine.currentMouldInstallation.mould', 'product', 'branch', 'mould', 'recipe', 'productionOrder'])
                    ->whereDate('production_date', $selectedDate)
                    ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
                    ->when($machineStatusFilter, fn ($q) => $q->whereHas('machine', fn ($m) => $m->where('status', $machineStatusFilter)))
                    ->when($productFilter, fn ($q) => $q->where('product_id', $productFilter))
                    ->orderBy('planned_start_time')->orderBy('id');
                $assignments = $scheduleQuery->paginate(12);
                $orderEligibleAssignmentIds = ProductionMachineAssignment::query()->forCurrentCompany()
                    ->eligibleForProductionOrder()
                    ->whereDate('production_date', $selectedDate)
                    ->pluck('id');
                Log::debug('Daily Schedule assignments loaded', [
                    'date' => $selectedDate,
                    'assignment_ids' => $assignments->getCollection()->pluck('id')->all(),
                    'order_eligible_assignment_ids' => $orderEligibleAssignmentIds->all(),
                    'sql' => $scheduleQuery->toSql(),
                    'bindings' => $scheduleQuery->getBindings(),
                ]);
            @endphp
            <div class="hidden md:block">
                <x-table :headers="['Machine', 'Mould', 'Product / Recipe', 'Date', 'Target', 'Status', 'Branch', 'Actions']">
                    @forelse ($assignments as $assignment)
                        @php
                            $requiresReassignment = $this->requiresReassignment($assignment);
                            $mouldHasChanged = $this->mouldHasChanged($assignment);
                            $linkedOrder = $assignment->immutableProductionOrder();
                            $historicalOrder = $assignment->historicalProductionOrder();
                            $currentMould = $assignment->machine?->currentMouldInstallation?->mould;
                        @endphp
                        <tr>
                            <td class="px-4 py-3 font-black">{{ $assignment->machine?->name }}</td>
                            <td class="px-4 py-3"><p class="font-bold text-slate-900 dark:text-white">{{ $currentMould?->name ?: __('production.moulds.none_installed') }}</p>@if($requiresReassignment)<p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ __('production.schedule.assigned_mould', ['mould' => $assignment->mould?->name ?: '—']) }}</p>@elseif($historicalOrder && $mouldHasChanged)<p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">Historical assignment — linked to completed Production Order {{ $historicalOrder->order_number }}. Create a new assignment.</p>@endif</td>
                            <td class="px-4 py-3"><p>{{ $assignment->product?->name }}</p><p class="text-xs text-slate-500">{{ $assignment->recipe?->name ?: '—' }}</p></td>
                            <td class="px-4 py-3">{{ $assignment->production_date->format('d M Y') }}</td>
                            <td class="px-4 py-3">{{ $assignment->target_quantity !== null ? number_format((float) $assignment->target_quantity, 2) : '—' }}</td>
                            <td class="px-4 py-3"><span class="{{ $assignment->status === 'confirmed' || $assignment->status === 'completed' ? 'badge-success' : ($assignment->status === 'cancelled' ? 'badge-danger' : 'badge-warning') }}">{{ ucfirst($assignment->status) }}</span>@if($requiresReassignment)<span class="mt-1 block rounded-full border border-amber-300 bg-amber-50 px-2 py-1 text-center text-[11px] font-black text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">{{ __('production.schedule.requires_reassignment') }}</span>@endif</td>
                            <td class="px-4 py-3">{{ $assignment->branch?->name ?: 'Company-wide' }}</td>
                            <td class="px-4 py-3"><div class="flex flex-wrap gap-1">
                                <button wire:click="viewAssignment({{ $assignment->id }})" class="rounded border px-2 py-1 text-xs font-bold">View</button>
                                @if($linkedOrder)
                                    <a href="{{ route('production.orders.show', $linkedOrder) }}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">View Order</a>
                                @elseif(auth()->user()?->can('production.create_orders') && $orderEligibleAssignmentIds->contains($assignment->id))
                                    <a href="{{ route('production.orders.create', ['assignment' => $assignment->id]) }}" wire:navigate class="rounded bg-build-orange px-2 py-1 text-xs font-bold text-white">Create Production Order</a>
                                @endif
                                @if($this->canManage() && $historicalOrder)
                                    <button wire:click="createNewAssignmentFrom({{ $assignment->id }})" class="rounded border border-cyan-500 px-2 py-1 text-xs font-bold text-cyan-700 dark:text-cyan-300">Create New Assignment From This</button>
                                @elseif($this->canManage() && ! $linkedOrder && $assignment->status !== 'completed')
                                    @if($assignment->status === 'cancelled')
                                        <button wire:click="createNewAssignmentFrom({{ $assignment->id }})" class="rounded border border-cyan-500 px-2 py-1 text-xs font-bold text-cyan-700 dark:text-cyan-300">Create Replacement</button>
                                        <button wire:click="editAssignment({{ $assignment->id }})" class="rounded border px-2 py-1 text-xs font-bold">Reactivate</button>
                                    @else
                                        <button wire:click="editAssignment({{ $assignment->id }})" class="rounded border px-2 py-1 text-xs font-bold">{{ $requiresReassignment ? __('production.schedule.reassign') : 'Edit' }}</button>
                                    @endif
                                    @if ($assignment->status !== 'cancelled')<button wire:click="setAssignmentStatus({{ $assignment->id }}, 'cancelled')" class="rounded bg-red-600 px-2 py-1 text-xs font-bold text-white">Cancel</button>@endif
                                    @if ($assignment->status === 'planned')<button wire:click="setAssignmentStatus({{ $assignment->id }}, 'confirmed')" class="rounded bg-cyan-700 px-2 py-1 text-xs font-bold text-white">Confirm</button>@endif
                                    @if ($assignment->status === 'confirmed')<button wire:click="setAssignmentStatus({{ $assignment->id }}, 'completed')" wire:confirm="Close this schedule only? This will not create stock." class="rounded bg-emerald-700 px-2 py-1 text-xs font-bold text-white">Complete Schedule</button>@endif
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No assignments for this date.</td></tr>
                    @endforelse
                </x-table>
            </div>
            <div class="space-y-3 md:hidden">
                @forelse ($assignments as $assignment)
                    @php
                        $requiresReassignment = $this->requiresReassignment($assignment);
                        $mouldHasChanged = $this->mouldHasChanged($assignment);
                        $linkedOrder = $assignment->immutableProductionOrder();
                        $historicalOrder = $assignment->historicalProductionOrder();
                        $currentMould = $assignment->machine?->currentMouldInstallation?->mould;
                    @endphp
                    <article class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-2"><div><h3 class="font-black">{{ $assignment->machine?->name }}</h3><p class="text-sm text-slate-500">{{ $currentMould?->name ?: __('production.moulds.none_installed') }} · {{ $assignment->product?->name }}</p><p class="text-xs text-slate-500">{{ $assignment->recipe?->name }}</p>@if($requiresReassignment)<p class="mt-1 text-xs font-black text-amber-700 dark:text-amber-300">{{ __('production.schedule.requires_reassignment') }} · {{ __('production.schedule.assigned_mould', ['mould' => $assignment->mould?->name ?: '—']) }}</p>@elseif($historicalOrder && $mouldHasChanged)<p class="mt-1 text-xs font-black text-amber-700 dark:text-amber-300">Historical assignment — linked to completed Production Order {{ $historicalOrder->order_number }}. Create a new assignment.</p>@endif</div><span class="badge-warning">{{ ucfirst($assignment->status) }}</span></div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm"><div><dt class="text-slate-500">Target</dt><dd>{{ $assignment->target_quantity ?? '—' }}</dd></div><div><dt class="text-slate-500">Branch</dt><dd>{{ $assignment->branch?->name ?: 'Company-wide' }}</dd></div></dl>
                        <button wire:click="viewAssignment({{ $assignment->id }})" class="mt-3 rounded border px-3 py-1 text-xs font-bold">View details</button>
                        @if($this->canManage() && $historicalOrder)<button wire:click="createNewAssignmentFrom({{ $assignment->id }})" class="mt-3 rounded border border-cyan-500 px-3 py-1 text-xs font-bold text-cyan-700 dark:text-cyan-300">Create New Assignment From This</button>@elseif($this->canManage() && ! $linkedOrder && $assignment->status !== 'completed')@if($assignment->status === 'cancelled')<button wire:click="createNewAssignmentFrom({{ $assignment->id }})" class="mt-3 rounded border border-cyan-500 px-3 py-1 text-xs font-bold text-cyan-700 dark:text-cyan-300">Create Replacement</button><button wire:click="editAssignment({{ $assignment->id }})" class="mt-3 rounded border px-3 py-1 text-xs font-bold">Reactivate</button>@else<button wire:click="editAssignment({{ $assignment->id }})" class="mt-3 rounded border px-3 py-1 text-xs font-bold">{{ $requiresReassignment ? __('production.schedule.reassign') : 'Edit' }}</button>@endif @endif
                    </article>
                @empty
                    <p class="py-8 text-center text-slate-500">No assignments for this date.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $assignments->links() }}</div>
            @if ($viewingId && ($viewing = ProductionMachineAssignment::query()->forCurrentCompany()->with(['machine.currentMouldInstallation.mould', 'mould', 'product', 'recipe', 'branch', 'creator', 'updater'])->find($viewingId)))
                <div class="mt-5 rounded-xl border p-4">
                    <div class="flex justify-between"><h3 class="font-black">Assignment Details</h3><button wire:click="$set('viewingId', null)">✕</button></div>
                    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">Machine</dt><dd>{{ $viewing->machine?->name }}</dd></div><div><dt class="text-slate-500">{{ __('production.schedule.current_mould') }}</dt><dd>{{ $viewing->machine?->currentMouldInstallation?->mould?->name ?: __('production.moulds.none_installed') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.schedule.assigned_mould_label') }}</dt><dd>{{ $viewing->mould?->name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Product</dt><dd>{{ $viewing->product?->name }}</dd></div><div><dt class="text-slate-500">Recipe</dt><dd>{{ $viewing->recipe?->name }}</dd></div>
                        <div><dt class="text-slate-500">Time</dt><dd>{{ $viewing->planned_start_time ?: '—' }} – {{ $viewing->planned_end_time ?: '—' }}</dd></div><div><dt class="text-slate-500">Notes</dt><dd>{{ $viewing->notes ?: '—' }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs font-bold text-amber-700">Planning record only — no materials or finished-goods stock are moved.</p>
                </div>
            @endif
        </x-card>
    </div>
</div>
