<?php

use App\Models\User;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'selected_user_id' => null]);

$openPermissions = function (int $userId) {
    $this->selected_user_id = $userId;
    $this->dispatch('open-modal', 'user-permissions');
};

$givePermission = function (int $permissionId) {
    $user = User::findOrFail($this->selected_user_id);
    $permission = Permission::findOrFail($permissionId);

    $user->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', "{$permission->name} permission added to {$user->name}.");
};

$removePermission = function (int $permissionId) {
    $user = User::findOrFail($this->selected_user_id);
    $permission = Permission::findOrFail($permissionId);

    $user->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', "{$permission->name} permission removed from {$user->name}.");
};

$toggleStatus = function (int $userId) {
    $user = User::findOrFail($userId);

    if ($user->hasRole('Super Admin') && User::role('Super Admin')->where('status', 'active')->count() <= 1) {
        session()->flash('error', 'You cannot deactivate the last active Super Admin.');
        return;
    }

    $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'User status updated.');
};

$deleteUser = function (int $userId) {
    $user = User::findOrFail($userId);

    if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
        session()->flash('error', 'You cannot delete the last Super Admin.');
        return;
    }

    $user->delete();

    session()->flash('success', 'User deleted.');
};

?>

<div>
    <x-page-header
        title="Users"
        description="Manage user access, status, branch assignment, and roles."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => null]"
    >
        <a href="{{ route('users.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create User</a>
    </x-page-header>

    <x-card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 sm:max-w-sm" placeholder="Search users...">
            <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export</button>
        </div>

        @php
            $users = User::query()
                ->with(['branch', 'company', 'roles'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['User', 'Phone', 'Roles', 'Company', 'Branch', 'Status', 'Actions']">
            @forelse ($users as $user)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img class="h-10 w-10 rounded-lg object-cover" src="{{ $user->profile_photo ? asset('storage/'.$user->profile_photo) : 'https://ui-avatars.com/api/?name='.urlencode($user->name).'&background=0d2e50&color=fff' }}" alt="{{ $user->name }}">
                            <div>
                                <p class="font-black">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">{{ $user->phone ?? '-' }}</td>
                    <td class="px-4 py-3">
                        {{ $user->roles->pluck('name')->join(', ') ?: '-' }}
                    </td>
                    <td class="px-4 py-3">{{ $user->company?->company_name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $user->branch?->name ?? '-' }}</td>
                    <td class="px-4 py-3"><span class="{{ $user->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($user->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('users.edit', $user) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                            <button wire:click="openPermissions({{ $user->id }})" class="rounded-lg bg-navy-900 px-3 py-1.5 text-xs font-bold text-white dark:bg-white dark:text-navy-900">Permissions</button>
                            <button wire:click="toggleStatus({{ $user->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                            <button wire:click="deleteUser({{ $user->id }})" wire:confirm="Delete this user?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No users found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $users->links() }}</div>
    </x-card>

    @php
        $selectedUser = $selected_user_id
            ? User::with(['roles.permissions', 'permissions'])->find($selected_user_id)
            : null;
        $allPermissions = Permission::query()->where('guard_name', 'web')->orderBy('name')->get();
        $directPermissionIds = $selectedUser?->permissions->pluck('id') ?? collect();
        $directPermissionNames = $selectedUser?->permissions->pluck('name') ?? collect();
        $inheritedPermissions = $selectedUser
            ? $selectedUser->getPermissionsViaRoles()->sortBy('name')->values()
            : collect();
        $effectivePermissionNames = $selectedUser
            ? $selectedUser->getAllPermissions()->pluck('name')
            : collect();
        $availablePermissions = $allPermissions->reject(fn ($permission) => $effectivePermissionNames->contains($permission->name));
    @endphp

    <x-modal name="user-permissions" maxWidth="2xl">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-black text-navy-900 dark:text-white">User Permissions</h2>
                    @if ($selectedUser)
                        <p class="mt-1 text-sm font-semibold text-slate-500">{{ $selectedUser->name }} · {{ $selectedUser->email }}</p>
                    @endif
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'user-permissions')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-bold dark:border-slate-700">Close</button>
            </div>

            @if ($selectedUser)
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <section>
                        <h3 class="text-sm font-black text-slate-700 dark:text-slate-200">Permissions User Has</h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500">Direct permissions can be removed. Role permissions are inherited.</p>

                        <div class="mt-3 max-h-96 space-y-4 overflow-y-auto pr-1">
                            <div>
                                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">Direct</p>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($selectedUser->permissions->sortBy('name') as $permission)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                                            {{ $permission->name }}
                                            <button
                                                type="button"
                                                wire:click="removePermission({{ $permission->id }})"
                                                wire:confirm="Remove {{ $permission->name }} from {{ $selectedUser->name }}?"
                                                class="rounded-full px-1 text-emerald-900 hover:bg-emerald-100 dark:text-emerald-100 dark:hover:bg-emerald-500/20"
                                                title="Remove permission"
                                            >x</button>
                                        </span>
                                    @empty
                                        <span class="text-sm font-semibold text-slate-500">No direct permissions.</span>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">From Roles</p>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($inheritedPermissions as $permission)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $permission->name }}</span>
                                    @empty
                                        <span class="text-sm font-semibold text-slate-500">No permissions inherited from roles.</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-black text-slate-700 dark:text-slate-200">Permissions User Does Not Have</h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500">Click a permission to give it directly to this user.</p>

                        <div class="mt-3 max-h-96 overflow-y-auto pr-1">
                            <div class="flex flex-wrap gap-2">
                                @forelse ($availablePermissions as $permission)
                                    <button
                                        type="button"
                                        wire:click="givePermission({{ $permission->id }})"
                                        class="rounded-full border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:border-build-orange hover:text-build-orange dark:border-slate-700 dark:text-slate-300"
                                        title="Give permission"
                                    >+ {{ $permission->name }}</button>
                                @empty
                                    <span class="text-sm font-semibold text-slate-500">This user already has every permission.</span>
                                @endforelse
                            </div>
                        </div>
                    </section>
                </div>
            @else
                <p class="mt-5 text-sm font-semibold text-slate-500">Select a user to manage permissions.</p>
            @endif
        </div>
    </x-modal>
</div>
