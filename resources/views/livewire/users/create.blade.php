<?php

use App\Models\Branch;
use App\Models\User;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Role;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'name' => '',
    'email' => '',
    'phone' => '',
    'password' => '',
    'status' => 'active',
    'role' => '',
    'branch_id' => '',
    'profile_photo' => '',
    'sales_location_access' => 'dispensing',
]);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
    'phone' => ['nullable', 'string', 'max:30'],
    'password' => ['required'],
    'status' => ['required', 'in:active,inactive'],
    'role' => ['required', 'exists:roles,name'],
    'branch_id' => ['nullable', 'exists:branches,id'],
    'profile_photo' => ['nullable', 'string', 'max:255'],
    'sales_location_access' => [
        InventorySettings::warehouseEnabled() && in_array($this->role, ['Super Admin', 'Admin', 'Manager', 'Cashier'], true) ? 'required' : 'nullable',
        Rule::in(['store', 'dispensing', 'both']),
    ],
]);

$showSalesLocationAccess = fn (): bool => InventorySettings::warehouseEnabled()
    && in_array($this->role, ['Super Admin', 'Admin', 'Manager', 'Cashier'], true);

$save = function () {
    $validated = $this->validate();

    validator(['password' => $this->password], ['password' => [Rules\Password::defaults()]])->validate();

    $user = User::create([
        'branch_id' => $validated['branch_id'] ?: null,
        'name' => $validated['name'],
        'email' => $validated['email'],
        'phone' => $validated['phone'],
        'profile_photo' => $validated['profile_photo'],
        'status' => $validated['status'],
        'sales_location_access' => InventorySettings::warehouseEnabled()
            ? ($validated['sales_location_access'] ?: 'dispensing')
            : 'dispensing',
        'password' => Hash::make($validated['password']),
    ]);

    $user->assignRole($validated['role']);

    session()->flash('success', 'User created successfully.');
    $this->redirectRoute('users.index', navigate: true);
};

?>

<div>
    <x-page-header
        title="Create User"
        description="Create a Hardex user and assign their branch and role."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => route('users.index'), 'Create' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Name" name="name" wire:model="name" required />
            <x-form-input label="Email" name="email" type="email" wire:model="email" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" />
            <x-form-input label="Password" name="password" type="password" wire:model="password" required />
            <x-form-input label="Profile Photo Path" name="profile_photo" wire:model="profile_photo" placeholder="users/avatar.jpg" />

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

            @if ($this->showSalesLocationAccess())
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    Sehemu Anayoruhusiwa Kuuza
                    <select wire:model="sales_location_access" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="store">Ghala Kuu Pekee</option>
                        <option value="dispensing">Sehemu ya Mauzo Pekee</option>
                        <option value="both">Kote Kote</option>
                    </select>
                    @error('sales_location_access') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
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
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save User</button>
                <a href="{{ route('users.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
