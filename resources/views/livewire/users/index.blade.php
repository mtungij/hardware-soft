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
    $user = User::query()->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))->findOrFail($this->selected_user_id);
    $permission = Permission::findOrFail($permissionId);

    $user->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', __('feedback.permission_added', ['permission' => \App\Support\UiText::permission($permission->name), 'name' => $user->name]));
};

$removePermission = function (int $permissionId) {
    $user = User::query()->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))->findOrFail($this->selected_user_id);
    $permission = Permission::findOrFail($permissionId);

    $user->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', __('feedback.permission_removed', ['permission' => \App\Support\UiText::permission($permission->name), 'name' => $user->name]));
};

$toggleStatus = function (int $userId) {
    $user = User::query()->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))->findOrFail($userId);

    if ($user->hasRole('Super Admin') && User::role('Super Admin')->where('status', 'active')->count() <= 1) {
        session()->flash('error', __('application.you_cannot_deactivate_the_last_active_super_admin_0978d126'));
        return;
    }

    $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.user_status_updated_24bf5fbd'));
};

$deleteUser = function (int $userId) {
    $user = User::query()->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))->findOrFail($userId);

    if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
        session()->flash('error', __('application.you_cannot_delete_the_last_super_admin_2d3c1dc6'));
        return;
    }

    $user->delete();

    session()->flash('success', __('application.user_deleted_0ba72d0b'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.users_57f2b181') }}"
        description="{{ __('application.manage_user_access_status_branch_assignment_and_roles_c1be8f1e') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => null]"
    >
        <x-export-actions export="tables.users" :params="compact('search')" />
        <a href="{{ route('users.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_user_b25c91fe') }}</a>
    </x-page-header>

    <x-card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 sm:max-w-sm" placeholder="{{ __('application.search_users_8cdc4c09') }}">
        </div>

        @php
            $users = User::query()->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))
                ->with(['branch', 'company', 'roles', 'stockLocations.branch'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="[__('application.user_9f8a2389'), __('application.phone_77064d52'), __('application.roles_47dcc27d'), __('terminology.assigned_stock_locations_4b54f9a3'), __('application.company_7a199499'), __('application.branch_1627510b'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
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
                        {{ $user->roles->pluck('name')->map(\App\Support\UiText::translate(...))->join(', ') ?: '-' }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($user->stockLocations->isNotEmpty())
                            <div class="space-y-1 text-xs">
                                @foreach ($user->stockLocations->take(3) as $location)
                                    <p class="font-semibold">{{ $location->name }} @if($location->pivot->is_default)<span class="badge-success">{{ __('application.default_808d7dca') }}</span>@endif</p>
                                @endforeach
                                @if ($user->stockLocations->count() > 3)
                                    <p class="text-slate-500">+{{ $user->stockLocations->count() - 3 }} {{ __('application.more_e7c95b4c') }}</p>
                                @endif
                            </div>
                        @else
                            <span class="text-xs text-slate-500">{{ __('application.legacy_372b9b1f') }} {{ \App\Support\UiText::label($user->sales_location_access ?? 'dispensing') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $user->company?->company_name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $user->branch?->name ?? '-' }}</td>
                    <td class="px-4 py-3"><span class="{{ $user->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($user->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('users.edit', $user) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                            <button wire:click="openPermissions({{ $user->id }})" class="rounded-lg bg-navy-900 px-3 py-1.5 text-xs font-bold text-white dark:bg-white dark:text-navy-900">{{ __('application.permissions_d06d5557') }}</button>
                            <button wire:click="toggleStatus({{ $user->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $user->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                            <button wire:click="deleteUser({{ $user->id }})" wire:confirm="{{ __('application.delete_this_user_452a20c5') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_users_found_e611ef57') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $users->links() }}</div>
    </x-card>

    @php
        $selectedUser = $selected_user_id
            ? User::with(['roles.permissions', 'permissions'])->when(\App\Support\BranchAccess::restricted(), fn ($query) => $query->where('branch_id', auth()->user()->branch_id))->find($selected_user_id)
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
                    <h2 class="text-lg font-black text-navy-900 dark:text-white">{{ __('application.user_permissions_c930fd60') }}</h2>
                    @if ($selectedUser)
                        <p class="mt-1 text-sm font-semibold text-slate-500">{{ $selectedUser->name }} · {{ $selectedUser->email }}</p>
                    @endif
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'user-permissions')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-bold dark:border-slate-700">{{ __('application.close_bbfa773e') }}</button>
            </div>

            @if ($selectedUser)
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <section>
                        <h3 class="text-sm font-black text-slate-700 dark:text-slate-200">{{ __('application.permissions_user_has_f3448c67') }}</h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ __('application.direct_permissions_can_be_removed_role_permissions_are_inherited_4037509c') }}</p>

                        <div class="mt-3 max-h-96 space-y-4 overflow-y-auto pr-1">
                            <div>
                                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">{{ __('application.direct_bc81524a') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($selectedUser->permissions->sortBy('name') as $permission)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                                            {{ \App\Support\UiText::permission($permission->name) }}
                                            <button
                                                type="button"
                                                wire:click="removePermission({{ $permission->id }})"
                                                wire:confirm="{{ __('feedback.remove_permission', ['permission' => \App\Support\UiText::permission($permission->name), 'name' => $selectedUser->name]) }}"
                                                class="rounded-full px-1 text-emerald-900 hover:bg-emerald-100 dark:text-emerald-100 dark:hover:bg-emerald-500/20"
                                                title="{{ __('application.remove_permission_21149220') }}"
                                            >x</button>
                                        </span>
                                    @empty
                                        <span class="text-sm font-semibold text-slate-500">{{ __('application.no_direct_permissions_4ab36288') }}</span>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">{{ __('application.from_roles_577b38e9') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($inheritedPermissions as $permission)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ \App\Support\UiText::permission($permission->name) }}</span>
                                    @empty
                                        <span class="text-sm font-semibold text-slate-500">{{ __('application.no_permissions_inherited_from_roles_85bfb7fb') }}</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-black text-slate-700 dark:text-slate-200">{{ __('application.permissions_user_does_not_have_a42f173b') }}</h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ __('application.click_a_permission_to_give_it_directly_to_this_user_7325a942') }}</p>

                        <div class="mt-3 max-h-96 overflow-y-auto pr-1">
                            <div class="flex flex-wrap gap-2">
                                @forelse ($availablePermissions as $permission)
                                    <button
                                        type="button"
                                        wire:click="givePermission({{ $permission->id }})"
                                        class="rounded-full border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:border-build-orange hover:text-build-orange dark:border-slate-700 dark:text-slate-300"
                                        title="{{ __('application.give_permission_17987686') }}"
                                    >+ {{ \App\Support\UiText::permission($permission->name) }}</button>
                                @empty
                                    <span class="text-sm font-semibold text-slate-500">{{ __('application.this_user_already_has_every_permission_6d1c1083') }}</span>
                                @endforelse
                            </div>
                        </div>
                    </section>
                </div>
            @else
                <p class="mt-5 text-sm font-semibold text-slate-500">{{ __('application.select_a_user_to_manage_permissions_0805ae25') }}</p>
            @endif
        </div>
    </x-modal>
</div>
