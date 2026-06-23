<?php

use App\Models\Branch;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '']);

$toggleStatus = function (int $branchId) {
    $branch = Branch::findOrFail($branchId);
    $branch->update(['status' => $branch->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Branch status updated.');
};

$deleteBranch = function (int $branchId) {
    $branch = Branch::withCount('users')->findOrFail($branchId);

    if ($branch->users_count > 0) {
        session()->flash('error', 'Deactivate branches that still have assigned users.');
        return;
    }

    $branch->delete();
    session()->flash('success', 'Branch deleted.');
};

?>

<div>
    <x-page-header
        title="Branches"
        description="Manage operating branches for user assignment and future inventory locations."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Branches' => null]"
    >
        <a href="{{ route('branches.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Branch</a>
    </x-page-header>

    <x-card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 sm:max-w-sm" placeholder="Search branches...">
            <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export</button>
        </div>

        @php
            $branches = Branch::query()
                ->withCount('users')
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%")))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['Branch', 'Contact', 'Region', 'Users', 'Status', 'Actions']">
            @forelse ($branches as $branch)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $branch->name }}</p><p class="text-xs text-slate-500">{{ $branch->code }}</p></td>
                    <td class="px-4 py-3"><p>{{ $branch->phone ?? '-' }}</p><p class="text-xs text-slate-500">{{ $branch->email ?? '-' }}</p></td>
                    <td class="px-4 py-3">{{ $branch->region ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $branch->users_count }}</td>
                    <td class="px-4 py-3"><span class="{{ $branch->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($branch->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('branches.edit', $branch) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                            <button wire:click="toggleStatus({{ $branch->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $branch->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                            <button wire:click="deleteBranch({{ $branch->id }})" wire:confirm="Delete this branch?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No branches found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $branches->links() }}</div>
    </x-card>
</div>
