<?php

use App\Models\Branch;
use App\Models\Machine;
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
    'search' => '',
    'statusFilter' => '',
    'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'editingId' => null,
    'viewingId' => null,
    'name' => '',
    'code' => '',
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'daily_capacity' => '',
    'capacity_unit' => 'pcs_per_day',
    'status' => Machine::STATUS_ACTIVE,
    'description' => '',
    'notes' => '',
]);

mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.view'),
    403
));

rules(fn () => [
    'name' => [
        'required', 'string', 'max:255',
        Rule::unique('machines', 'name')
            ->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId()))
            ->ignore($this->editingId),
    ],
    'code' => [
        'nullable', 'string', 'max:100',
        Rule::unique('machines', 'code')
            ->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId()))
            ->ignore($this->editingId),
    ],
    'branch_id' => [
        'nullable',
        Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId())),
    ],
    'daily_capacity' => ['nullable', 'numeric', 'min:0'],
    'capacity_unit' => ['required', 'string', 'max:40'],
    'status' => ['required', Rule::in(Machine::STATUSES)],
    'description' => ['nullable', 'string', 'max:2000'],
    'notes' => ['nullable', 'string', 'max:2000'],
]);

$canManage = fn (): bool => auth()->user()?->can('production.manage_machines') ?? false;

$resetForm = function (): void {
    $this->reset(['editingId', 'name', 'code', 'branch_id', 'daily_capacity', 'description', 'notes']);
    $this->capacity_unit = 'pcs_per_day';
    $this->status = Machine::STATUS_ACTIVE;
    $this->resetValidation();
};

$save = function (): void {
    abort_unless($this->canManage(), 403);
    $validated = $this->validate();
    $validated['company_id'] = CompanyFeatures::companyId();
    $validated['branch_id'] = filled($validated['branch_id']) ? $validated['branch_id'] : null;
    $validated['code'] = filled($validated['code']) ? $validated['code'] : null;
    $validated['daily_capacity'] = filled($validated['daily_capacity']) ? $validated['daily_capacity'] : null;
    $validated['updated_by'] = auth()->id();

    $machine = $this->editingId
        ? Machine::query()->forCurrentCompany()->findOrFail($this->editingId)
        : new Machine(['created_by' => auth()->id()]);

    $machine->fill($validated)->save();
    $this->resetForm();
    session()->flash('success', __('application.machine_saved_successfully_a0fa26ee'));
};

$editMachine = function (int $machineId): void {
    abort_unless($this->canManage(), 403);
    $machine = Machine::query()->forCurrentCompany()->findOrFail($machineId);
    $this->editingId = $machine->id;
    $this->name = $machine->name;
    $this->code = $machine->code ?? '';
    $this->branch_id = $machine->branch_id ? (string) $machine->branch_id : '';
    $this->daily_capacity = $machine->daily_capacity ?? '';
    $this->capacity_unit = $machine->capacity_unit;
    $this->status = $machine->status;
    $this->description = $machine->description ?? '';
    $this->notes = $machine->notes ?? '';
};

$viewMachine = fn (int $machineId) => $this->viewingId = Machine::query()
    ->forCurrentCompany()->findOrFail($machineId)->id;

$setStatus = function (int $machineId, string $status): void {
    abort_unless($this->canManage(), 403);
    abort_unless(in_array($status, Machine::STATUSES, true), 422);
    Machine::query()->forCurrentCompany()->findOrFail($machineId)
        ->update(['status' => $status, 'updated_by' => auth()->id()]);
    session()->flash('success', __('application.machine_status_updated_1442f98c'));
};

$archiveMachine = function (int $machineId): void {
    abort_unless($this->canManage(), 403);
    $machine = Machine::query()->forCurrentCompany()->withCount('dailyAssignments')->findOrFail($machineId);

    if ($machine->daily_assignments_count > 0) {
        session()->flash('error', __('application.a_machine_with_schedule_history_cannot_be_archived_set_it_inactive_instead_cbdb3b65'));
        return;
    }

    $machine->delete();
    session()->flash('success', __('application.machine_archived_73e41bd2'));
};

?>

<div>
    <x-page-header
        :title="__('production.machines')"
        description="{{ __('application.manage_production_equipment_and_informational_daily_capacity_94bc5659') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.machines') => null]"
    />

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ session('error') }}</div> @endif

    <div class="grid gap-6 {{ $this->canManage() ? 'xl:grid-cols-[400px_1fr]' : '' }}">
        @if ($this->canManage())
            <x-card :title="$editingId ? __('terminology.edit_machine_87acaa86') : __('terminology.create_machine_8c3f50a2')">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="{{ __('application.machine_name_f1b060bc') }}" name="name" wire:model="name" required />
                    <x-form-input label="{{ __('application.machine_code_9d1ff9d1') }}" name="code" wire:model="code" />
                    <label class="block text-sm font-bold">{{ __('application.branch_production_site_becf974b') }}
                        <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                            <option value="">{{ __('application.company_wide_24f7fca3') }}</option>
                            @foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <x-form-input label="{{ __('application.daily_capacity_a030c2f0') }}" name="daily_capacity" type="number" step="0.0001" min="0" wire:model="daily_capacity" />
                        <label class="block text-sm font-bold">{{ __('application.capacity_unit_fe9ae67d') }}
                            <select wire:model="capacity_unit" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                                <option value="pcs_per_day">{{ __('application.pieces_day_cabff608') }}</option>
                                <option value="units_per_day">{{ __('application.units_day_3916a048') }}</option>
                                <option value="kg_per_day">{{ __('application.kg_day_5443dcf6') }}</option>
                                <option value="m3_per_day">{{ __('application.m_day_9bc163b3') }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block text-sm font-bold">{{ __('application.status_bae7d5be') }}
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">{{ __('application.active_inafanya_kazi_7194ccb4') }}</option>
                            <option value="inactive">{{ __('application.inactive_haifanyi_kazi_2fbfd82d') }}</option>
                            <option value="maintenance">{{ __('application.maintenance_94de303b') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-bold">{{ __('application.description_55f8ebc8') }}
                        <textarea wire:model="description" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    <label class="block text-sm font-bold">{{ __('application.notes_70440046') }}
                        <textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_machine_563d4d04') }}</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.clear_719ea396') }}</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="{{ __('application.machine_list_37b6ecd7') }}">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <input wire:model.live.debounce.300ms="search" placeholder="{{ __('application.search_name_or_code_96db3f70') }}" class="rounded-lg border-slate-200 md:col-span-3 dark:border-slate-700 dark:bg-navy-950">
                <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.all_statuses_6405179d') }}</option><option value="active">{{ __('application.active_a733b809') }}</option><option value="inactive">{{ __('application.inactive_09af574c') }}</option><option value="maintenance">{{ __('application.maintenance_94de303b') }}</option>
                </select>
                <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                    <option value="">{{ __('application.all_branches_25c23746') }}</option>
                    @foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            @php
                $machines = Machine::query()->forCurrentCompany()->with(['branch', 'currentMouldInstallation.mould', 'latestMouldInstallation'])->withCount('dailyAssignments')
                    ->when($search, fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                    ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
                    ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
                    ->orderBy('name')->paginate(10);
            @endphp
            <x-table :headers="[__('application.machine_65bb1b78'), __('application.branch_1627510b'), __('terminology.current_mould_478bf5d6'), __('terminology.last_installation_577aa3f2'), __('terminology.capacity_45bd908d'), __('application.status_bae7d5be'), __('terminology.assignments_057d58c7'), __('application.actions_c3cd636a')]">
                @forelse ($machines as $machine)
                    <tr>
                        <td class="px-4 py-3"><p class="font-black">{{ $machine->name }}</p><p class="text-xs text-slate-500">{{ $machine->code ?: '—' }}</p></td>
                        <td class="px-4 py-3">{{ $machine->branch?->name ?: __('application.company_wide_24f7fca3') }}</td>
                        <td class="px-4 py-3">{{ $machine->currentMouldInstallation?->mould?->name ?: __('production.moulds.none_installed') }}</td>
                        <td class="px-4 py-3">{{ $machine->latestMouldInstallation?->installed_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $machine->daily_capacity !== null ? number_format((float) $machine->daily_capacity, 2).' '.str_replace('_', ' ', $machine->capacity_unit) : '—' }}</td>
                        <td class="px-4 py-3"><span class="{{ $machine->status === 'active' ? 'badge-success' : ($machine->status === 'maintenance' ? 'badge-warning' : 'badge-danger') }}">{{ \App\Support\UiText::status($machine->status) }}</span></td>
                        <td class="px-4 py-3">{{ $machine->daily_assignments_count }}</td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                            <button wire:click="viewMachine({{ $machine->id }})" class="rounded-lg border px-2 py-1 text-xs font-bold">{{ __('application.view_69bd4ef9') }}</button>
                            @if ($this->canManage())
                                <button wire:click="editMachine({{ $machine->id }})" class="rounded-lg border px-2 py-1 text-xs font-bold">{{ __('application.edit_5301648d') }}</button>
                                <select wire:change="setStatus({{ $machine->id }}, $event.target.value)" class="rounded-lg border px-2 py-1 text-xs">
                                    @foreach (Machine::STATUSES as $status)<option value="{{ $status }}" @selected($machine->status === $status)>{{ \App\Support\UiText::label($status) }}</option>@endforeach
                                </select>
                                <button wire:click="archiveMachine({{ $machine->id }})" wire:confirm="{{ __('application.archive_this_unused_machine_7e8048b6') }}" class="rounded-lg bg-red-600 px-2 py-1 text-xs font-bold text-white">{{ __('application.archive_2621c6fd') }}</button>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_machines_found_63164146') }}</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4">{{ $machines->links() }}</div>
            @if ($viewingId && ($viewing = Machine::query()->forCurrentCompany()->with(['branch', 'creator', 'updater', 'currentMouldInstallation.mould.family', 'latestMouldInstallation'])->find($viewingId)))
                <div class="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex justify-between"><h3 class="font-black">{{ $viewing->name }}</h3><button wire:click="$set('viewingId', null)">✕</button></div>
                    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">{{ __('application.code_adac6937') }}</dt><dd>{{ $viewing->code ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('application.site_5b6825d3') }}</dt><dd>{{ $viewing->branch?->name ?: __('application.company_wide_24f7fca3') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.current_mould') }}</dt><dd>{{ $viewing->currentMouldInstallation?->mould?->name ?: __('production.moulds.none_installed') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.family') }}</dt><dd>{{ $viewing->currentMouldInstallation?->mould?->family?->name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.last_installation') }}</dt><dd>{{ $viewing->latestMouldInstallation?->installed_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('application.description_55f8ebc8') }}</dt><dd>{{ $viewing->description ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('application.notes_70440046') }}</dt><dd>{{ $viewing->notes ?: '—' }}</dd></div>
                    </dl>
                </div>
            @endif
        </x-card>
    </div>
</div>
