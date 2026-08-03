<?php

use App\Models\Machine;
use App\Models\ProductFamily;
use App\Models\ProductionMould;
use App\Models\ProductionMouldInstallation;
use App\Services\ProductionMouldService;
use App\Support\CompanyFeatures;
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
    'search' => '', 'familyFilter' => '', 'statusFilter' => '', 'maintenanceFilter' => '',
    'installationFilter' => '', 'machineFilter' => '', 'editingId' => null, 'viewingId' => null,
    'code' => '', 'name' => '', 'product_family_id' => '', 'compatible_machine_ids' => [],
    'expected_output_per_cycle' => '', 'expected_output_per_day' => '', 'active' => true,
    'description' => '', 'notes' => '', 'installation_machine_id' => '',
    'installation_mould_id' => '', 'installation_notes' => '',
]);

mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled()
    && collect(['production.view_moulds', 'production.manage_moulds'])->contains(fn ($permission) => auth()->user()?->can($permission)),
    403,
));

rules(fn () => [
    'code' => ['required', 'string', 'max:100', Rule::unique('production_moulds', 'code')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId()))->ignore($this->editingId)],
    'name' => ['required', 'string', 'max:255'],
    'product_family_id' => ['required', Rule::exists('product_families', 'id')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId())->where('active', true))],
    'compatible_machine_ids' => ['required', 'array', 'min:1'],
    'compatible_machine_ids.*' => [Rule::exists('machines', 'id')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId())->whereNull('deleted_at'))],
    'expected_output_per_cycle' => ['nullable', 'numeric', 'gt:0'],
    'expected_output_per_day' => ['nullable', 'numeric', 'gt:0'],
    'active' => ['boolean'],
    'description' => ['nullable', 'string', 'max:2000'],
    'notes' => ['nullable', 'string', 'max:2000'],
]);

$canManage = fn (): bool => auth()->user()?->can('production.manage_moulds') ?? false;

$resetForm = function (): void {
    $this->reset(['editingId', 'code', 'name', 'product_family_id', 'compatible_machine_ids', 'expected_output_per_cycle', 'expected_output_per_day', 'description', 'notes']);
    $this->active = true;
    $this->resetValidation();
};

$resetFilters = function (): void {
    $this->reset(['search', 'familyFilter', 'statusFilter', 'maintenanceFilter', 'installationFilter', 'machineFilter']);
    $this->resetPage();
};

$save = function (): void {
    abort_unless($this->canManage(), 403);
    $validated = $this->validate();
    $machineIds = array_map('intval', $validated['compatible_machine_ids']);
    unset($validated['compatible_machine_ids']);
    $validated['company_id'] = CompanyFeatures::companyId();
    $validated['expected_output_per_cycle'] = filled($validated['expected_output_per_cycle']) ? $validated['expected_output_per_cycle'] : null;
    $validated['expected_output_per_day'] = filled($validated['expected_output_per_day']) ? $validated['expected_output_per_day'] : null;
    $validated['updated_by'] = auth()->id();

    $mould = $this->editingId
        ? ProductionMould::query()->forCurrentCompany()->with('currentInstallations')->withCount('installations')->findOrFail($this->editingId)
        : new ProductionMould(['created_by' => auth()->id()]);
    if ($mould->exists && $mould->installations_count > 0 && (int) $mould->product_family_id !== (int) $validated['product_family_id']) {
        $this->addError('product_family_id', __('production.moulds.validation.used_family_immutable'));

        return;
    }
    $installedMachineId = $mould->currentInstallations->first()?->current_machine_id;
    if ($installedMachineId && ! in_array((int) $installedMachineId, $machineIds, true)) {
        $this->addError('compatible_machine_ids', __('production.moulds.validation.installed_machine_required'));

        return;
    }

    $mould->fill($validated)->save();
    $mould->compatibleMachines()->syncWithPivotValues($machineIds, ['company_id' => CompanyFeatures::companyId()]);
    $this->resetForm();
    session()->flash('success', __('production.moulds.saved'));
};

$editMould = function (int $mouldId): void {
    abort_unless($this->canManage(), 403);
    $mould = ProductionMould::query()->forCurrentCompany()->with('compatibleMachines')->findOrFail($mouldId);
    $this->editingId = $mould->id;
    $this->code = $mould->code;
    $this->name = $mould->name;
    $this->product_family_id = (string) $mould->product_family_id;
    $this->compatible_machine_ids = $mould->compatibleMachines->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->expected_output_per_cycle = $mould->expected_output_per_cycle ?? '';
    $this->expected_output_per_day = $mould->expected_output_per_day ?? '';
    $this->active = (bool) $mould->active;
    $this->description = $mould->description ?? '';
    $this->notes = $mould->notes ?? '';
    $this->resetValidation();
};

$deleteMould = function (int $mouldId): void {
    abort_unless($this->canManage(), 403);
    $mould = ProductionMould::query()->forCurrentCompany()->withCount(['installations', 'assignments'])->findOrFail($mouldId);
    if ($mould->installations_count > 0 || $mould->assignments_count > 0) {
        session()->flash('error', __('production.moulds.in_use'));

        return;
    }
    $mould->compatibleMachines()->detach();
    $mould->delete();
    session()->flash('success', __('production.moulds.deleted'));
};

$installSelected = function (): void {
    abort_unless($this->canManage(), 403);
    $this->validate(['installation_machine_id' => ['required'], 'installation_mould_id' => ['required'], 'installation_notes' => ['nullable', 'string', 'max:2000']]);
    $machine = Machine::query()->forCurrentCompany()->findOrFail($this->installation_machine_id);
    $mould = ProductionMould::query()->forCurrentCompany()->findOrFail($this->installation_mould_id);
    app(ProductionMouldService::class)->install($machine, $mould, auth()->user(), $this->installation_notes);
    $this->reset(['installation_mould_id', 'installation_notes']);
    $this->dispatch('mould-installation-updated', machineId: $machine->id);
    session()->flash('success', __('production.moulds.installed'));
};

$replaceSelected = function (): void {
    abort_unless($this->canManage(), 403);
    $this->validate(['installation_machine_id' => ['required'], 'installation_mould_id' => ['required'], 'installation_notes' => ['nullable', 'string', 'max:2000']]);
    $machine = Machine::query()->forCurrentCompany()->findOrFail($this->installation_machine_id);
    $mould = ProductionMould::query()->forCurrentCompany()->findOrFail($this->installation_mould_id);
    app(ProductionMouldService::class)->replace($machine, $mould, auth()->user(), $this->installation_notes);
    $this->reset(['installation_mould_id', 'installation_notes']);
    $this->dispatch('mould-installation-updated', machineId: $machine->id);
    session()->flash('success', __('production.moulds.replaced'));
};

$removeInstalled = function (int $machineId): void {
    abort_unless($this->canManage(), 403);
    app(ProductionMouldService::class)->remove(Machine::query()->forCurrentCompany()->findOrFail($machineId), auth()->user(), $this->installation_notes);
    $this->dispatch('mould-installation-updated', machineId: $machineId);
    session()->flash('success', __('production.moulds.removed'));
};

$startMaintenance = function (int $mouldId): void {
    abort_unless($this->canManage(), 403);
    $mould = ProductionMould::query()->forCurrentCompany()->with('currentInstallations')->findOrFail($mouldId);
    $machineId = $mould->currentInstallations->first()?->current_machine_id;
    app(ProductionMouldService::class)->startMaintenance($mould, auth()->user(), $this->installation_notes);
    $this->dispatch('mould-installation-updated', machineId: $machineId);
    session()->flash('success', __('production.moulds.maintenance_started'));
};

$completeMaintenance = function (int $mouldId): void {
    abort_unless($this->canManage(), 403);
    app(ProductionMouldService::class)->completeMaintenance(ProductionMould::query()->forCurrentCompany()->findOrFail($mouldId), auth()->user());
    $this->dispatch('mould-installation-updated');
    session()->flash('success', __('production.moulds.maintenance_completed'));
};

$summary = function (): array {
    $base = ProductionMould::query()->forCurrentCompany();

    return [
        'total' => (clone $base)->count(),
        'active' => (clone $base)->where('active', true)->where('under_maintenance', false)->count(),
        'installed' => (clone $base)->whereHas('currentInstallations')->count(),
        'available' => (clone $base)->available()->whereDoesntHave('currentInstallations')->count(),
        'maintenance' => (clone $base)->where('under_maintenance', true)->count(),
        'machines' => Machine::query()->forCurrentCompany()->whereHas('compatibleMoulds')->count(),
    ];
};

$moulds = function () {
    return ProductionMould::query()->forCurrentCompany()
        ->with(['family', 'compatibleMachines', 'currentInstallations.machine', 'installations'])
        ->withCount(['installations', 'assignments', 'compatibleMachines'])
        ->when(filled($this->search), fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', '%'.$this->search.'%')->orWhere('code', 'like', '%'.$this->search.'%')))
        ->when(filled($this->familyFilter), fn ($query) => $query->where('product_family_id', $this->familyFilter))
        ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
        ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
        ->when($this->maintenanceFilter === 'yes', fn ($query) => $query->where('under_maintenance', true))
        ->when($this->maintenanceFilter === 'no', fn ($query) => $query->where('under_maintenance', false))
        ->when($this->installationFilter === 'installed', fn ($query) => $query->whereHas('currentInstallations'))
        ->when($this->installationFilter === 'available', fn ($query) => $query->available()->whereDoesntHave('currentInstallations'))
        ->when(filled($this->machineFilter), fn ($query) => $query->whereHas('compatibleMachines', fn ($machineQuery) => $machineQuery->whereKey($this->machineFilter)))
        ->orderBy('name')->paginate(12);
};

$maintenanceMoulds = fn () => ProductionMould::query()->forCurrentCompany()
    ->where('under_maintenance', true)->with(['family', 'installations.remover'])->orderBy('name')->get();

$historyEvents = function () {
    return ProductionMouldInstallation::query()->forCurrentCompany()
        ->with(['machine', 'mould', 'installer', 'remover'])->latest('installed_at')->limit(30)->get()
        ->flatMap(function (ProductionMouldInstallation $installation): array {
            $events = [[
                'type' => 'installed', 'at' => $installation->installed_at, 'mould' => $installation->mould,
                'machine' => $installation->machine, 'actor' => $installation->installer, 'notes' => $installation->notes,
            ]];
            if ($installation->removed_at) {
                $events[] = [
                    'type' => $installation->removal_reason === ProductionMouldInstallation::REASON_MAINTENANCE ? 'maintenance_started' : $installation->removal_reason,
                    'at' => $installation->removed_at, 'mould' => $installation->mould,
                    'machine' => $installation->machine, 'actor' => $installation->remover, 'notes' => $installation->notes,
                ];
            }

            return $events;
        })->sortByDesc('at')->values();
};

?>

<div class="min-w-0">
    <x-page-header :title="__('production.moulds.title')" :description="__('production.moulds.description')" :breadcrumbs="[__('production.moulds.dashboard') => route('dashboard'), __('production.title') => route('production.index'), __('production.moulds.title') => null]" />

    @if(session('success'))<div role="status" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif
    @if(session('error'))<div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">{{ session('error') }}</div>@endif

    @php
        $machines = Machine::query()->forCurrentCompany()->with('currentMouldInstallation.mould.family')->orderBy('name')->get();
        $families = ProductFamily::query()->forCurrentCompany()->active()->orderBy('name')->get();
        $statistics = $this->summary();
        $activeFilterCount = collect([$search, $familyFilter, $statusFilter, $maintenanceFilter, $installationFilter, $machineFilter])->filter(fn ($value) => filled($value))->count();
    @endphp

    <nav aria-label="{{ __('production.moulds.workspace_navigation') }}" class="mb-5 flex max-w-full flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        @foreach(['overview', 'catalog', 'installation_workspace', 'maintenance_workspace', 'history'] as $section)
            <a href="#{{ str_replace('_', '-', $section) }}" class="rounded-lg px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white">{{ __('production.moulds.'.$section) }}</a>
        @endforeach
    </nav>

    <section id="overview" aria-labelledby="overview-heading" class="scroll-mt-4">
        <h2 id="overview-heading" class="sr-only">{{ __('production.moulds.overview') }}</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach([
                ['total', 'total_moulds', 'bg-cyan-500'], ['active', 'active_moulds', 'bg-emerald-500'], ['installed', 'installed_moulds', 'bg-blue-500'],
                ['available', 'available_moulds', 'bg-teal-500'], ['maintenance', 'under_maintenance', 'bg-amber-500'], ['machines', 'compatible_machines', 'bg-violet-500'],
            ] as [$key, $label, $tone])
                <article data-testid="mould-stat-{{ $key }}" class="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('production.moulds.'.$label) }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-950 dark:text-white">{{ $statistics[$key] }}</p>
                    <span class="mt-3 block h-1.5 w-10 rounded-full {{ $tone }}" aria-hidden="true"></span>
                </article>
            @endforeach
        </div>
    </section>

    @if($this->canManage())
        <section id="mould-form" aria-labelledby="mould-form-heading" class="mt-6 scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div><h2 id="mould-form-heading" class="text-lg font-black text-slate-950 dark:text-white">{{ $editingId ? __('production.moulds.edit') : __('production.moulds.create_new') }}</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.form_help') }}</p></div>
                <span class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">{{ $editingId ? __('production.moulds.edit_mode') : __('production.moulds.create_mode') }}</span>
            </div>
            <form wire:submit="save" class="space-y-6">
                <fieldset><legend class="mb-3 text-sm font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">{{ __('production.moulds.identification') }}</legend><div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <x-form-input :label="__('production.moulds.code')" name="code" wire:model="code" required />
                    <x-form-input :label="__('production.moulds.name')" name="name" wire:model="name" required />
                    <label for="product_family_id" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.family') }} <span class="text-red-600" aria-hidden="true">*</span><select id="product_family_id" wire:model="product_family_id" aria-describedby="product-family-error" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.select_family') }}</option>@foreach($families as $family)<option value="{{ $family->id }}">{{ $family->name }}</option>@endforeach</select>@error('product_family_id')<span id="product-family-error" class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                    <label class="flex min-h-11 items-center gap-3 self-end rounded-lg border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"><input type="checkbox" wire:model="active" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">{{ __('production.moulds.active_status') }}</label>
                </div></fieldset>
                <fieldset><legend class="mb-3 text-sm font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">{{ __('production.moulds.production_capacity') }}</legend><div class="grid gap-4 md:grid-cols-2">
                    <x-form-input :label="__('production.moulds.output_cycle')" name="expected_output_per_cycle" type="number" min="0.000000000001" step="any" wire:model="expected_output_per_cycle" />
                    <x-form-input :label="__('production.moulds.output_day')" name="expected_output_per_day" type="number" min="0.000000000001" step="any" wire:model="expected_output_per_day" />
                </div><p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('production.moulds.capacity_help') }}</p></fieldset>
                <fieldset class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60"><legend class="px-1 text-sm font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">{{ __('production.moulds.compatibility') }}</legend><p class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ __('production.moulds.compatibility_help') }}</p><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach($machines as $machine)<label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><input type="checkbox" wire:model="compatible_machine_ids" value="{{ $machine->id }}" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">{{ $machine->name }}</label>@endforeach</div>@error('compatible_machine_ids')<span class="mt-2 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</fieldset>
                <fieldset><legend class="mb-3 text-sm font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">{{ __('production.moulds.details') }}</legend><div class="grid gap-4 md:grid-cols-2">
                    <label for="description" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.description_field') }}<textarea id="description" wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea>@error('description')<span class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                    <label for="notes" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.notes') }}<textarea id="notes" wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea>@error('notes')<span class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                </div></fieldset>
                <div class="flex flex-col-reverse gap-2 sm:flex-row"><button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-600 px-5 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-cyan-700 active:bg-cyan-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-cyan-500 dark:text-slate-950 dark:hover:bg-cyan-400 dark:focus:ring-offset-slate-900" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? __('production.moulds.save_changes') : __('production.moulds.save') }}</button><button type="button" wire:click="resetForm" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-black text-slate-900 transition hover:bg-slate-50 active:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800 dark:focus:ring-offset-slate-900">{{ $editingId ? __('production.moulds.cancel_editing') : __('production.moulds.clear_form') }}</button></div>
            </form>
        </section>
    @else
        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ __('production.moulds.read_only') }}</div>
    @endif

    <div class="mt-6 grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <section id="catalog" aria-labelledby="catalog-heading" class="min-w-0 scroll-mt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="catalog-heading" class="text-lg font-black text-slate-950 dark:text-white">{{ __('production.moulds.catalog') }}</h2><p class="text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.catalog_help') }}</p></div>@if($activeFilterCount)<span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-black text-cyan-800 dark:bg-cyan-500/15 dark:text-cyan-100">{{ trans_choice('production.moulds.active_filters', $activeFilterCount, ['count' => $activeFilterCount]) }}</span>@endif</div>
            <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-form-input :label="__('production.moulds.search')" name="search" wire:model.live.debounce.300ms="search" />
                    <label for="family-filter" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.family') }}<select id="family-filter" wire:model.live="familyFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.all_families') }}</option>@foreach($families as $family)<option value="{{ $family->id }}">{{ $family->name }}</option>@endforeach</select></label>
                    <label for="status-filter" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.status') }}<select id="status-filter" wire:model.live="statusFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.all_statuses') }}</option><option value="active">{{ __('production.moulds.active') }}</option><option value="inactive">{{ __('production.moulds.inactive') }}</option></select></label>
                    <label for="maintenance-filter" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.maintenance_state') }}<select id="maintenance-filter" wire:model.live="maintenanceFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.all_maintenance_states') }}</option><option value="yes">{{ __('production.moulds.under_maintenance') }}</option><option value="no">{{ __('production.moulds.not_under_maintenance') }}</option></select></label>
                    <label for="installation-filter" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.installation_state') }}<select id="installation-filter" wire:model.live="installationFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.all_installation_states') }}</option><option value="installed">{{ __('production.moulds.installed_moulds') }}</option><option value="available">{{ __('production.moulds.available_moulds') }}</option></select></label>
                    <label for="machine-filter" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.compatible_machine') }}<select id="machine-filter" wire:model.live="machineFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.all_machines') }}</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select></label>
                </div>
                <button type="button" wire:click="resetFilters" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-900 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.moulds.reset_filters') }}</button>
            </div>

            @php $mouldPage = $this->moulds(); @endphp
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                @forelse($mouldPage as $mould)
                    @php
                        $colourClass = match($mould->family?->colour) {
                            'cyan' => 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100', 'sky' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100', 'blue' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100', 'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100', 'violet' => 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-100', 'teal' => 'border-teal-200 bg-teal-50 text-teal-800 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-100', 'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100', 'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100', 'orange' => 'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-100', 'red' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100', default => 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100',
                        };
                        $currentInstallation = $mould->currentInstallations->first();
                        $lastInstallation = $mould->installations->sortByDesc('installed_at')->first();
                    @endphp
                    <article data-testid="mould-card" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-cyan-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-900 dark:hover:border-cyan-500/50">
                        <div class="flex min-w-0 items-start justify-between gap-3"><div class="flex min-w-0 items-center gap-3"><span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border text-sm font-black {{ $colourClass }}" title="{{ $mould->family?->icon }}" aria-hidden="true">{{ str($mould->family?->icon ?: 'm')->substr(0, 1)->upper() }}</span><div class="min-w-0"><h3 class="truncate font-black text-slate-950 dark:text-white">{{ $mould->name }}</h3><p class="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $mould->code }}</p></div></div><span class="shrink-0 rounded-full border px-2 py-1 text-[11px] font-bold {{ $mould->active ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100' : 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200' }}">{{ $mould->active ? __('production.moulds.active') : __('production.moulds.inactive') }}</span></div>
                        <div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full border px-2.5 py-1 text-xs font-bold {{ $colourClass }}">{{ $mould->family?->name }}</span>@if($mould->under_maintenance)<span class="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100">{{ __('production.moulds.under_maintenance') }}</span>@endif</div>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.moulds.current_machine') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $currentInstallation?->machine?->name ?: __('production.moulds.not_installed') }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.moulds.compatible_machines') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $mould->compatible_machines_count }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.moulds.output_cycle') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $mould->expected_output_per_cycle ? __('production.moulds.units_per_cycle', ['value' => $mould->expected_output_per_cycle]) : '—' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.moulds.output_day') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $mould->expected_output_per_day ? __('production.moulds.units_per_day', ['value' => $mould->expected_output_per_day]) : '—' }}</dd></div><div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.moulds.last_installation') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $lastInstallation?->installed_at?->format('d M Y H:i') ?: '—' }}</dd></div></dl>
                        @if($viewingId === $mould->id)<div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"><p><span class="font-black">{{ __('production.moulds.description_field') }}:</span> {{ $mould->description ?: '—' }}</p><p class="mt-2"><span class="font-black">{{ __('production.moulds.notes') }}:</span> {{ $mould->notes ?: '—' }}</p><p class="mt-2"><span class="font-black">{{ __('production.moulds.compatible_machines') }}:</span> {{ $mould->compatibleMachines->pluck('name')->join(', ') ?: '—' }}</p></div>@endif
                        <div class="mt-4 flex flex-wrap gap-2"><button type="button" wire:click="$set('viewingId', {{ $viewingId === $mould->id ? 'null' : $mould->id }})" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-900 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ $viewingId === $mould->id ? __('production.moulds.hide_details') : __('production.moulds.view') }}</button>@if($this->canManage())<button type="button" wire:click="editMould({{ $mould->id }})" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-900 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.moulds.edit_action') }}</button>@if(!$currentInstallation && $mould->active && !$mould->under_maintenance)<a href="#installation-workspace" wire:click="$set('installation_mould_id', '{{ $mould->id }}')" class="inline-flex min-h-10 items-center rounded-lg bg-cyan-600 px-3 py-2 text-xs font-black text-white hover:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:bg-cyan-500 dark:text-slate-950 dark:hover:bg-cyan-400">{{ __('production.moulds.install') }}</a>@endif @if($mould->under_maintenance)<button type="button" wire:click="completeMaintenance({{ $mould->id }})" wire:confirm="{{ __('production.moulds.confirm_complete_maintenance', ['mould' => $mould->name]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">{{ __('production.moulds.complete_maintenance') }}</button>@else<button type="button" wire:click="startMaintenance({{ $mould->id }})" wire:confirm="{{ __('production.moulds.confirm_maintenance', ['mould' => $mould->name]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-amber-500 px-3 py-2 text-xs font-black text-slate-950 hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500">{{ __('production.moulds.start_maintenance') }}</button>@endif @if($mould->installations_count === 0 && $mould->assignments_count === 0)<button type="button" wire:click="deleteMould({{ $mould->id }})" wire:confirm="{{ __('production.moulds.delete_confirm') }}" class="inline-flex min-h-10 items-center rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-black text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-red-500/40 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/10">{{ __('production.moulds.delete') }}</button>@endif @endif</div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 md:col-span-2">{{ __('production.moulds.empty') }}</div>
                @endforelse
            </div>
            <div class="mt-4">{{ $mouldPage->links() }}</div>
        </section>

        <section id="installation-workspace" aria-labelledby="installation-heading" class="min-w-0 scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 xl:sticky xl:top-4">
            <div class="mb-4"><h2 id="installation-heading" class="text-lg font-black text-slate-950 dark:text-white">{{ __('production.moulds.current_installation') }}</h2><p class="text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.installation_help') }}</p></div>
            @if($this->canManage())
                <div class="space-y-4">
                    <label for="installation-machine" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.moulds.machine') }}<select id="installation-machine" wire:model.live="installation_machine_id" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.select_machine') }}</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select>@error('installation_machine_id')<span class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                    @php
                        $selectedMachine = filled($installation_machine_id)
                            ? Machine::query()->forCurrentCompany()->whereKey($installation_machine_id)->first()
                            : null;
                        $selectedInstallation = $selectedMachine?->currentMouldInstallation()->with('mould.family')->first();
                        $selectedCurrentMould = $selectedInstallation?->mould;
                        $compatibleAvailableMoulds = $selectedMachine ? ProductionMould::query()->forCurrentCompany()->available()->whereHas('compatibleMachines', fn ($query) => $query->whereKey($selectedMachine->id))->whereDoesntHave('currentInstallations')->orderBy('name')->get() : collect();
                        $replacementMould = filled($installation_mould_id)
                            ? ProductionMould::query()->forCurrentCompany()->with(['compatibleMachines', 'currentInstallations'])->find($installation_mould_id)
                            : null;
                        $replacementIsCompatible = $selectedMachine && $replacementMould
                            ? $replacementMould->compatibleMachines->contains('id', $selectedMachine->id)
                            : false;
                        $canReplace = $this->canManage()
                            && $selectedMachine
                            && $selectedInstallation
                            && $selectedCurrentMould
                            && $replacementMould
                            && (int) $replacementMould->id !== (int) $selectedCurrentMould->id
                            && $replacementMould->active
                            && ! $replacementMould->under_maintenance
                            && $replacementMould->currentInstallations->isEmpty()
                            && $replacementIsCompatible;
                        $canInstall = $this->canManage()
                            && $selectedMachine
                            && ! $selectedInstallation
                            && $replacementMould
                            && $replacementMould->active
                            && ! $replacementMould->under_maintenance
                            && $replacementMould->currentInstallations->isEmpty()
                            && $replacementIsCompatible;
                    @endphp
                    @if($selectedMachine)
                        <dl data-testid="mould-current-installation" data-installation-id="{{ $selectedInstallation?->id }}" class="grid gap-3 rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm dark:border-cyan-500/30 dark:bg-cyan-500/10 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2"><div><dt class="text-xs font-bold uppercase text-cyan-700 dark:text-cyan-200">{{ __('production.moulds.machine') }}</dt><dd class="font-black text-slate-950 dark:text-white">{{ $selectedMachine->name }}</dd></div><div><dt class="text-xs font-bold uppercase text-cyan-700 dark:text-cyan-200">{{ __('production.moulds.current_mould') }}</dt><dd class="font-black text-slate-950 dark:text-white">{{ $selectedCurrentMould?->name ?: __('production.moulds.none_installed') }}</dd></div><div><dt class="text-xs font-bold uppercase text-cyan-700 dark:text-cyan-200">{{ __('production.moulds.installed_since') }}</dt><dd class="font-bold text-slate-900 dark:text-slate-100">{{ $selectedInstallation?->installed_at?->format('d M Y H:i') ?: '—' }}</dd></div><div><dt class="text-xs font-bold uppercase text-cyan-700 dark:text-cyan-200">{{ __('production.moulds.current_mould_status') }}</dt><dd class="font-bold text-slate-900 dark:text-slate-100">{{ $selectedCurrentMould ? ($selectedCurrentMould->under_maintenance ? __('production.moulds.under_maintenance') : ($selectedCurrentMould->active ? __('production.moulds.active') : __('production.moulds.inactive'))) : '—' }}</dd></div><div class="sm:col-span-2 xl:col-span-1 2xl:col-span-2"><dt class="text-xs font-bold uppercase text-cyan-700 dark:text-cyan-200">{{ __('production.moulds.production_family') }}</dt><dd class="font-bold text-slate-900 dark:text-slate-100">{{ $selectedCurrentMould?->family?->name ?: '—' }}</dd></div></dl>
                        @if($selectedCurrentMould?->under_maintenance)<div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">{{ __('production.moulds.maintenance_action_unavailable') }}</div>@endif
                        <label for="installation-mould" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ $selectedInstallation ? __('production.moulds.replace_with') : __('production.moulds.select_compatible_mould') }}<select id="installation-mould" wire:model.live="installation_mould_id" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ __('production.moulds.select_mould') }}</option>@foreach($compatibleAvailableMoulds as $mould)<option value="{{ $mould->id }}">{{ $mould->name }}</option>@endforeach</select>@error('installation_mould_id')<span class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                        @if($compatibleAvailableMoulds->isEmpty())<p class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('production.moulds.no_compatible_moulds') }}</p>@endif
                        <label for="installation-notes" class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ $selectedInstallation ? __('production.moulds.replacement_notes') : __('production.moulds.installation_notes') }}<textarea id="installation-notes" wire:model="installation_notes" class="mt-1 block min-h-24 w-full rounded-lg border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea>@error('installation_notes')<span class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</span>@enderror</label>
                        <div class="flex flex-col gap-2 sm:flex-row xl:flex-col 2xl:flex-row">@if($selectedInstallation)<button data-testid="replace-mould-button" data-replace-eligible="{{ $canReplace ? 'true' : 'false' }}" type="button" wire:click="replaceSelected" wire:confirm="{{ __('production.moulds.confirm_replacement', ['current' => $selectedCurrentMould?->name, 'replacement' => $replacementMould?->name ?: __('production.moulds.selected_mould')]) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-black text-white hover:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-cyan-500 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-cyan-500 dark:text-slate-950 dark:hover:bg-cyan-400" @disabled(! $canReplace)>{{ __('production.moulds.replace') }}</button><button type="button" wire:click="removeInstalled({{ $selectedMachine->id }})" wire:confirm="{{ __('production.moulds.confirm_removal', ['machine' => $selectedMachine->name]) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-black text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-red-500/40 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/10">{{ __('production.moulds.remove') }}</button>@else<button data-testid="install-mould-button" type="button" wire:click="installSelected" wire:confirm="{{ __('production.moulds.confirm_installation', ['mould' => $replacementMould?->name ?: __('production.moulds.selected_mould'), 'machine' => $selectedMachine->name]) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400" @disabled(! $canInstall)>{{ __('production.moulds.install') }}</button>@endif</div>
                    @else
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('production.moulds.select_machine_prompt') }}</div>
                    @endif
                </div>
            @else
                <p class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ __('production.moulds.read_only') }}</p>
            @endif
        </section>
    </div>

    <section id="maintenance-workspace" aria-labelledby="maintenance-heading" class="mt-6 scroll-mt-4">
        <div class="mb-4"><h2 id="maintenance-heading" class="text-lg font-black text-slate-950 dark:text-white">{{ __('production.moulds.maintenance_workspace') }}</h2><p class="text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.maintenance_help') }}</p></div>
        @php $maintenanceMouldList = $this->maintenanceMoulds(); @endphp
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($maintenanceMouldList as $mould)
                @php $maintenanceEvent = $mould->installations->where('removal_reason', ProductionMouldInstallation::REASON_MAINTENANCE)->sortByDesc('removed_at')->first(); @endphp
                <article class="rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-500/40 dark:bg-amber-500/10"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-wide text-amber-800 dark:text-amber-200">{{ __('production.moulds.under_maintenance') }}</p><h3 class="mt-1 font-black text-slate-950 dark:text-white">{{ $mould->name }}</h3></div><span class="rounded-full border border-amber-300 bg-white px-2.5 py-1 text-xs font-bold text-amber-900 dark:border-amber-500/40 dark:bg-slate-900 dark:text-amber-100">{{ $mould->family?->name }}</span></div><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-xs font-bold uppercase text-amber-800 dark:text-amber-200">{{ __('production.moulds.started') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $maintenanceEvent?->removed_at?->format('d M Y H:i') ?: '—' }}</dd></div><div><dt class="text-xs font-bold uppercase text-amber-800 dark:text-amber-200">{{ __('production.moulds.started_by') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $maintenanceEvent?->remover?->name ?: '—' }}</dd></div><div><dt class="text-xs font-bold uppercase text-amber-800 dark:text-amber-200">{{ __('production.moulds.maintenance_reason') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $maintenanceEvent?->notes ?: '—' }}</dd></div></dl><div class="mt-4 flex flex-wrap gap-2">@if($this->canManage())<button type="button" wire:click="completeMaintenance({{ $mould->id }})" wire:confirm="{{ __('production.moulds.confirm_complete_maintenance', ['mould' => $mould->name]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">{{ __('production.moulds.complete_maintenance') }}</button>@endif<a href="#history" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-amber-400 bg-white px-4 py-2.5 text-sm font-black text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:border-amber-500/50 dark:bg-slate-900 dark:text-amber-100 dark:hover:bg-amber-500/10">{{ __('production.moulds.view_history') }}</a></div></article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 md:col-span-2 xl:col-span-3">{{ __('production.moulds.no_active_maintenance') }}</div>
            @endforelse
        </div>
    </section>

    <section id="history" aria-labelledby="history-heading" class="mt-6 scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
        <div class="mb-5"><h2 id="history-heading" class="text-lg font-black text-slate-950 dark:text-white">{{ __('production.moulds.installation_history') }}</h2><p class="text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.history_help') }}</p></div>
        <ol class="relative ml-3 border-l border-slate-200 pl-6 dark:border-slate-700">
            @forelse($this->historyEvents() as $event)
                @php
                    $eventClass = match($event['type']) { 'installed' => 'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/15 dark:text-emerald-100', 'replaced' => 'border-cyan-300 bg-cyan-100 text-cyan-900 dark:border-cyan-500/40 dark:bg-cyan-500/15 dark:text-cyan-100', 'maintenance_started' => 'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100', default => 'border-red-300 bg-red-100 text-red-900 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-100' };
                @endphp
                <li class="relative pb-6 last:pb-0"><span class="absolute -left-[2.15rem] top-1 h-4 w-4 rounded-full border-2 border-white {{ str_contains($eventClass, 'emerald') ? 'bg-emerald-500' : (str_contains($eventClass, 'cyan') ? 'bg-cyan-500' : (str_contains($eventClass, 'amber') ? 'bg-amber-500' : 'bg-red-500')) }} ring-2 ring-slate-200 dark:border-slate-900 dark:ring-slate-700" aria-hidden="true"></span><article class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800"><div class="flex flex-wrap items-start justify-between gap-2"><span class="rounded-full border px-2.5 py-1 text-xs font-black {{ $eventClass }}">{{ __('production.moulds.events.'.$event['type']) }}</span><time datetime="{{ $event['at']?->toIso8601String() }}" class="text-xs font-bold text-slate-500 dark:text-slate-400">{{ $event['at']?->format('d M Y H:i') }}</time></div><h3 class="mt-3 font-black text-slate-950 dark:text-white">{{ $event['mould']?->name }}</h3><p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ __('production.moulds.machine_value', ['machine' => $event['machine']?->name ?: '—']) }}</p><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __('production.moulds.actor_value', ['actor' => $event['actor']?->name ?: '—']) }}</p>@if(filled($event['notes']))<p class="mt-2 rounded-lg bg-white p-2 text-sm text-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ $event['notes'] }}</p>@endif</article></li>
            @empty
                <li class="-ml-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('production.moulds.no_history') }}</li>
            @endforelse
        </ol>
    </section>
</div>
