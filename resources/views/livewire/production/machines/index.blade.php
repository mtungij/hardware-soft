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
    'branchFilter' => '',
    'editingId' => null,
    'viewingId' => null,
    'name' => '',
    'code' => '',
    'branch_id' => '',
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
    session()->flash('success', 'Machine saved successfully.');
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
    session()->flash('success', 'Machine status updated.');
};

$archiveMachine = function (int $machineId): void {
    abort_unless($this->canManage(), 403);
    $machine = Machine::query()->forCurrentCompany()->withCount('dailyAssignments')->findOrFail($machineId);

    if ($machine->daily_assignments_count > 0) {
        session()->flash('error', 'A machine with schedule history cannot be archived. Set it inactive instead.');
        return;
    }

    $machine->delete();
    session()->flash('success', 'Machine archived.');
};

?>

<div>
    <x-page-header
        :title="__('production.machines')"
        description="Manage production equipment and informational daily capacity."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.machines') => null]"
    />

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ session('error') }}</div> @endif

    <div class="grid gap-6 {{ $this->canManage() ? 'xl:grid-cols-[400px_1fr]' : '' }}">
        @if ($this->canManage())
            <x-card :title="$editingId ? 'Edit Machine' : 'Create Machine'">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="Machine Name / Jina la Mashine" name="name" wire:model="name" required />
                    <x-form-input label="Machine Code / Kodi ya Mashine" name="code" wire:model="code" />
                    <label class="block text-sm font-bold">Branch / Production Site
                        <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Company-wide</option>
                            @foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <x-form-input label="Daily Capacity" name="daily_capacity" type="number" step="0.0001" min="0" wire:model="daily_capacity" />
                        <label class="block text-sm font-bold">Capacity Unit
                            <select wire:model="capacity_unit" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                                <option value="pcs_per_day">Pieces/day</option>
                                <option value="units_per_day">Units/day</option>
                                <option value="kg_per_day">Kg/day</option>
                                <option value="m3_per_day">m³/day</option>
                            </select>
                        </label>
                    </div>
                    <label class="block text-sm font-bold">Status
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">Active / Inafanya kazi</option>
                            <option value="inactive">Inactive / Haifanyi kazi</option>
                            <option value="maintenance">Maintenance / Matengenezo</option>
                        </select>
                    </label>
                    <label class="block text-sm font-bold">Description
                        <textarea wire:model="description" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    <label class="block text-sm font-bold">Notes
                        <textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Machine</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border px-4 py-2.5 text-sm font-black">Clear</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="Machine List">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <input wire:model.live.debounce.300ms="search" placeholder="Search name or code..." class="rounded-lg border-slate-200 md:col-span-3 dark:border-slate-700 dark:bg-navy-950">
                <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="maintenance">Maintenance</option>
                </select>
                <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All branches</option>
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
            <x-table :headers="['Machine', 'Branch', 'Current Mould', 'Last Installation', 'Capacity', 'Status', 'Assignments', 'Actions']">
                @forelse ($machines as $machine)
                    <tr>
                        <td class="px-4 py-3"><p class="font-black">{{ $machine->name }}</p><p class="text-xs text-slate-500">{{ $machine->code ?: '—' }}</p></td>
                        <td class="px-4 py-3">{{ $machine->branch?->name ?: 'Company-wide' }}</td>
                        <td class="px-4 py-3">{{ $machine->currentMouldInstallation?->mould?->name ?: __('production.moulds.none_installed') }}</td>
                        <td class="px-4 py-3">{{ $machine->latestMouldInstallation?->installed_at?->format('d M Y H:i') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $machine->daily_capacity !== null ? number_format((float) $machine->daily_capacity, 2).' '.str_replace('_', ' ', $machine->capacity_unit) : '—' }}</td>
                        <td class="px-4 py-3"><span class="{{ $machine->status === 'active' ? 'badge-success' : ($machine->status === 'maintenance' ? 'badge-warning' : 'badge-danger') }}">{{ ucfirst($machine->status) }}</span></td>
                        <td class="px-4 py-3">{{ $machine->daily_assignments_count }}</td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                            <button wire:click="viewMachine({{ $machine->id }})" class="rounded-lg border px-2 py-1 text-xs font-bold">View</button>
                            @if ($this->canManage())
                                <button wire:click="editMachine({{ $machine->id }})" class="rounded-lg border px-2 py-1 text-xs font-bold">Edit</button>
                                <select wire:change="setStatus({{ $machine->id }}, $event.target.value)" class="rounded-lg border px-2 py-1 text-xs">
                                    @foreach (Machine::STATUSES as $status)<option value="{{ $status }}" @selected($machine->status === $status)>{{ ucfirst($status) }}</option>@endforeach
                                </select>
                                <button wire:click="archiveMachine({{ $machine->id }})" wire:confirm="Archive this unused machine?" class="rounded-lg bg-red-600 px-2 py-1 text-xs font-bold text-white">Archive</button>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No machines found.</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4">{{ $machines->links() }}</div>
            @if ($viewingId && ($viewing = Machine::query()->forCurrentCompany()->with(['branch', 'creator', 'updater', 'currentMouldInstallation.mould.family', 'latestMouldInstallation'])->find($viewingId)))
                <div class="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex justify-between"><h3 class="font-black">{{ $viewing->name }}</h3><button wire:click="$set('viewingId', null)">✕</button></div>
                    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">Code</dt><dd>{{ $viewing->code ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Site</dt><dd>{{ $viewing->branch?->name ?: 'Company-wide' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.current_mould') }}</dt><dd>{{ $viewing->currentMouldInstallation?->mould?->name ?: __('production.moulds.none_installed') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.family') }}</dt><dd>{{ $viewing->currentMouldInstallation?->mould?->family?->name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('production.moulds.last_installation') }}</dt><dd>{{ $viewing->latestMouldInstallation?->installed_at?->format('d M Y H:i') ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Description</dt><dd>{{ $viewing->description ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Notes</dt><dd>{{ $viewing->notes ?: '—' }}</dd></div>
                    </dl>
                </div>
            @endif
        </x-card>
    </div>
</div>
