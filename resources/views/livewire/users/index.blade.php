<?php

use App\Models\User;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '']);

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
                ->with(['branch', 'roles'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['User', 'Phone', 'Role', 'Branch', 'Status', 'Actions']">
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
                    <td class="px-4 py-3">{{ $user->roles->pluck('name')->join(', ') ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $user->branch?->name ?? '-' }}</td>
                    <td class="px-4 py-3"><span class="{{ $user->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($user->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('users.edit', $user) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                            <button wire:click="toggleStatus({{ $user->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                            <button wire:click="deleteUser({{ $user->id }})" wire:confirm="Delete this user?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No users found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $users->links() }}</div>
    </x-card>
</div>
