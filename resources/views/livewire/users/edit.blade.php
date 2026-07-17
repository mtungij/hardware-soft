<?php

use App\Models\Branch;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Role;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'user' => null,
    'name' => '',
    'email' => '',
    'phone' => '',
    'password' => '',
    'status' => 'active',
    'role' => '',
    'branch_id' => '',
    'profile_photo' => '',
    'sales_location_access' => 'dispensing',
    'location_access' => [],
    'location_search' => '',
    'location_branch_filter' => '',
    'location_type_filter' => '',
    'show_selected_only' => false,
]);

mount(function (User $user) {
    $this->user = $user;
    $this->name = $user->name;
    $this->email = $user->email;
    $this->phone = $user->phone;
    $this->status = $user->status;
    $this->role = $user->roles()->first()?->name ?? '';
    $this->branch_id = (string) $user->branch_id;
    $this->profile_photo = $user->profile_photo;
    $this->sales_location_access = $user->sales_location_access ?: 'dispensing';
    $this->location_access = $user->stockLocations()->get()->mapWithKeys(fn ($location) => [
        $location->id => [
            'selected' => true,
            'can_view' => (bool) $location->pivot->can_view,
            'can_sell' => (bool) $location->pivot->can_sell,
            'can_transfer' => (bool) $location->pivot->can_transfer,
            'can_receive' => (bool) $location->pivot->can_receive,
            'is_default' => (bool) $location->pivot->is_default,
        ],
    ])->all();
});

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
    'phone' => ['nullable', 'string', 'max:30'],
    'password' => ['nullable'],
    'status' => ['required', 'in:active,inactive'],
    'role' => ['required', 'exists:roles,name'],
    'branch_id' => ['nullable', 'exists:branches,id'],
    'profile_photo' => ['nullable', 'string', 'max:255'],
    'sales_location_access' => [
        'nullable',
        Rule::in(['store', 'dispensing', 'both']),
    ],
]);

$showStockLocationAccess = fn (): bool => in_array($this->role, ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper'], true);

$visibleStockLocations = function () {
    $assignedIds = collect($this->location_access)->filter(fn ($row) => (bool) ($row['selected'] ?? false))->keys()->map(fn ($id) => (int) $id);

    return StockLocation::query()
        ->with('branch')
        ->where(fn ($query) => $query->where(fn ($q) => $q->where('status', 'active')->where('is_active', true))->orWhereIn('id', $assignedIds))
        ->when($this->branch_id, fn ($query) => $query->where('branch_id', $this->branch_id))
        ->when($this->location_branch_filter, fn ($query) => $query->where('branch_id', $this->location_branch_filter))
        ->when($this->location_type_filter, fn ($query) => $query->where('type', $this->location_type_filter))
        ->when($this->location_search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$this->location_search}%")->orWhere('code', 'like', "%{$this->location_search}%")))
        ->orderBy('name')
        ->get()
        ->filter(fn (StockLocation $location) => ! $this->show_selected_only || (bool) data_get($this->location_access, "{$location->id}.selected"));
};

$filteredLocationIds = fn () => $this->visibleStockLocations()->pluck('id')->map(fn ($id) => (int) $id)->all();

$selectAllLocations = function () {
    foreach ($this->filteredLocationIds() as $id) {
        $this->location_access[$id]['selected'] = true;
        $this->location_access[$id]['can_view'] = true;
    }
};

$clearAllLocations = function () {
    foreach ($this->filteredLocationIds() as $id) {
        unset($this->location_access[$id]);
    }
};

$grantToSelected = function (string $field) {
    $locations = StockLocation::whereIn('id', $this->filteredLocationIds())->get()->keyBy('id');

    foreach ($this->filteredLocationIds() as $id) {
        if (! (bool) data_get($this->location_access, "{$id}.selected")) {
            continue;
        }

        $location = $locations->get($id);
        $this->location_access[$id]['can_view'] = true;

        if ($field === 'can_sell' && $location?->can_sell) {
            $this->location_access[$id]['can_sell'] = true;
        }
        if ($field === 'can_transfer' && $location?->can_transfer) {
            $this->location_access[$id]['can_transfer'] = true;
        }
        if ($field === 'can_receive' && $location?->can_receive_stock) {
            $this->location_access[$id]['can_receive'] = true;
        }
        if ($field === 'remove_sell') {
            $this->location_access[$id]['can_sell'] = false;
        }
    }
};

$syncStockLocationAccess = function () {
    $rows = [];
    $selectedCount = 0;
    $sellingCount = 0;
    $defaultCount = 0;

    foreach ($this->location_access as $locationId => $access) {
        if (! (bool) ($access['selected'] ?? false)) {
            continue;
        }

        $location = StockLocation::query()->whereKey($locationId)->firstOrFail();

        if (! $location->isActive()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => 'Inactive stock location cannot be newly assigned.']);
        }
        if ((bool) ($access['can_sell'] ?? false) && ! $location->can_sell) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => "{$location->name} does not allow sales."]);
        }
        if ((bool) ($access['can_transfer'] ?? false) && ! $location->can_transfer) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => "{$location->name} does not allow transfers."]);
        }
        if ((bool) ($access['can_receive'] ?? false) && ! $location->can_receive_stock) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => "{$location->name} does not allow receiving."]);
        }

        $selectedCount++;
        $sellingCount += (bool) ($access['can_sell'] ?? false) ? 1 : 0;
        $defaultCount += (bool) ($access['is_default'] ?? false) ? 1 : 0;

        $rows[(int) $locationId] = [
            'company_id' => $this->user->company_id,
            'branch_id' => $location->branch_id,
            'can_view' => (bool) ($access['can_view'] ?? true),
            'can_sell' => (bool) ($access['can_sell'] ?? false),
            'can_transfer' => (bool) ($access['can_transfer'] ?? false),
            'can_receive' => (bool) ($access['can_receive'] ?? false),
            'is_default' => (bool) ($access['is_default'] ?? false),
            'assigned_by' => auth()->id(),
        ];
    }

    if ($rows === []) {
        $types = match ($this->user->sales_location_access ?: 'dispensing') {
            'store' => ['store'],
            'both' => ['store', 'dispensing'],
            default => ['dispensing'],
        };

        foreach (StockLocation::query()->where('branch_id', $this->user->branch_id)->whereIn('type', $types)->get() as $index => $location) {
            $rows[$location->id] = [
                'company_id' => $this->user->company_id,
                'can_view' => true,
                'can_sell' => true,
                'can_transfer' => $location->can_transfer,
                'can_receive' => $location->can_receive_stock,
                'is_default' => $index === 0,
            ];
        }
    }

    if ($defaultCount > 1) {
        throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => 'Choose only one default stock location.']);
    }
    if ($defaultCount === 1 && collect($rows)->firstWhere('is_default', true)['can_view'] !== true) {
        throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => 'Default location must allow stock viewing.']);
    }
    if ($this->role === 'Cashier' && $sellingCount < 1) {
        throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => 'Cashier must have at least one selling location.']);
    }
    if ($this->role === 'Store Keeper' && $selectedCount < 1) {
        throw \Illuminate\Validation\ValidationException::withMessages(['location_access' => 'Store keeper must have at least one stock location.']);
    }

    $this->user->stockLocations()->sync($rows);
};

$save = function () {
    $validated = $this->validate();

    if ($this->password) {
        validator(['password' => $this->password], ['password' => [Rules\Password::defaults()]])->validate();
    }

    if ($this->user->hasRole('Super Admin') && $validated['status'] === 'inactive' && User::role('Super Admin')->where('status', 'active')->count() <= 1) {
        $this->addError('status', 'You cannot deactivate the last active Super Admin.');
        return;
    }

    $payload = [
        'branch_id' => $validated['branch_id'] ?: null,
        'name' => $validated['name'],
        'email' => $validated['email'],
        'phone' => $validated['phone'],
        'profile_photo' => $validated['profile_photo'],
        'status' => $validated['status'],
        'sales_location_access' => InventorySettings::warehouseEnabled()
            ? ($validated['sales_location_access'] ?: 'dispensing')
            : 'dispensing',
    ];

    if ($validated['password']) {
        $payload['password'] = Hash::make($validated['password']);
    }

    DB::transaction(function () use ($payload, $validated) {
        $this->user->update($payload);
        $this->user->syncRoles([$validated['role']]);
        $this->syncStockLocationAccess();
    });

    session()->flash('success', 'User updated successfully.');
    $this->redirectRoute('users.index', navigate: true);
};

?>

<div>
    <x-page-header
        title="Edit User"
        description="Update account details, role, branch, and active status."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => route('users.index'), 'Edit' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Name" name="name" wire:model="name" required />
            <x-form-input label="Email" name="email" type="email" wire:model="email" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" />
            <x-form-input label="New Password" name="password" type="password" wire:model="password" placeholder="Leave blank to keep current password" />
            <x-form-input label="Profile Photo Path" name="profile_photo" wire:model="profile_photo" />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Status
                <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                @error('status') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Assigned Role
                <select wire:model.live="role" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select role</option>
                    @foreach (Role::orderBy('name')->pluck('name') as $roleName)
                        <option value="{{ $roleName }}">{{ $roleName }}</option>
                    @endforeach
                </select>
                @error('role') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($this->showStockLocationAccess())
                <div class="md:col-span-2">
                    <div class="mb-3 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-sm font-black text-slate-700 dark:text-slate-200">Maeneo ya Stock Anayoruhusiwa</p>
                            <p class="mt-1 text-xs text-slate-500">Assigned Stock Locations for POS, transfers, receiving, and stock visibility.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="selectAllLocations" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Chagua Zote</button>
                            <button type="button" wire:click="clearAllLocations" wire:confirm="Remove access for filtered locations?" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Ondoa Zote</button>
                            <button type="button" wire:click="grantToSelected('can_sell')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Ruhusu Kuuza</button>
                            <button type="button" wire:click="grantToSelected('can_transfer')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Ruhusu Kuhamisha</button>
                            <button type="button" wire:click="grantToSelected('can_receive')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Ruhusu Kupokea</button>
                            <button type="button" wire:click="grantToSelected('remove_sell')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Ondoa Kuuza</button>
                        </div>
                    </div>

                    <div class="mb-3 grid gap-3 md:grid-cols-4">
                        <input wire:model.live.debounce.300ms="location_search" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Search location">
                        <select wire:model.live="location_branch_filter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">All branches</option>
                            @foreach (Branch::orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="location_type_filter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">All types</option>
                            @foreach (StockLocation::TYPES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">
                            <input type="checkbox" wire:model.live="show_selected_only" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                            Show selected only
                        </label>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                        <table class="min-w-[980px] w-full text-sm">
                            <thead class="sticky top-0 bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2">Chagua</th>
                                    <th class="px-3 py-2">Eneo la Stock</th>
                                    <th class="px-3 py-2">Aina</th>
                                    <th class="px-3 py-2">Tawi</th>
                                    <th class="px-3 py-2">Angalia</th>
                                    <th class="px-3 py-2">Uza</th>
                                    <th class="px-3 py-2">Hamisha</th>
                                    <th class="px-3 py-2">Pokea</th>
                                    <th class="px-3 py-2">Default</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @forelse ($this->visibleStockLocations() as $location)
                                    @php $selected = (bool) data_get($location_access, "{$location->id}.selected"); @endphp
                                    <tr class="{{ ! $location->isActive() ? 'opacity-60' : '' }}">
                                        <td class="px-3 py-2"><input type="checkbox" wire:model.live="location_access.{{ $location->id }}.selected" @disabled(! $location->isActive()) class="rounded border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                        <td class="px-3 py-2 font-bold">{{ InventorySettings::stockLocationLabel($location) }} @if(! $location->isActive()) <span class="badge-warning">Inactive</span> @endif</td>
                                        <td class="px-3 py-2">{{ StockLocation::TYPES[$location->type] ?? $location->type }}</td>
                                        <td class="px-3 py-2">{{ $location->branch?->name ?? '-' }}</td>
                                        <td class="px-3 py-2"><input type="checkbox" wire:model="location_access.{{ $location->id }}.can_view" @disabled(! $selected || ! $location->isActive()) class="rounded border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                        <td class="px-3 py-2"><input type="checkbox" wire:model="location_access.{{ $location->id }}.can_sell" @disabled(! $selected || ! $location->can_sell || ! $location->isActive()) class="rounded border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                        <td class="px-3 py-2"><input type="checkbox" wire:model="location_access.{{ $location->id }}.can_transfer" @disabled(! $selected || ! $location->can_transfer || ! $location->isActive()) class="rounded border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                        <td class="px-3 py-2"><input type="checkbox" wire:model="location_access.{{ $location->id }}.can_receive" @disabled(! $selected || ! $location->can_receive_stock || ! $location->isActive()) class="rounded border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                        <td class="px-3 py-2"><input type="radio" name="default_stock_location" wire:model="location_access.{{ $location->id }}.is_default" value="1" @disabled(! $selected || ! $location->isActive()) class="border-slate-300 text-build-orange focus:ring-build-orange"></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No stock locations found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @error('location_access') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </div>
            @endif

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Assigned Branch
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">No branch</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update User</button>
                <a href="{{ route('users.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
