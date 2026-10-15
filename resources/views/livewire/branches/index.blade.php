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

    session()->flash('success', __('application.branch_status_updated_4d7f833e'));
};

$deleteBranch = function (int $branchId) {
    $branch = Branch::withCount('users')->findOrFail($branchId);

    if ($branch->users_count > 0) {
        session()->flash('error', __('application.deactivate_branches_that_still_have_assigned_users_9ea24ca6'));
        return;
    }

    $branch->delete();
    session()->flash('success', __('application.branch_deleted_4ad2b1d2'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.branches_f5782279') }}"
        description="{{ __('application.manage_operating_branches_for_user_assignment_and_future_inventory_location_f47623b1') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Branches' => null]"
    >
        <x-export-actions export="tables.branches" :params="compact('search')" />
        <a href="{{ route('branches.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_branch_061f3327') }}</a>
    </x-page-header>

    <x-card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 sm:max-w-sm" placeholder="{{ __('application.search_branches_a51f2fb3') }}">
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

        <x-table :headers="[__('application.branch_1627510b'), __('terminology.contact_b37456c4'), __('application.region_0f217179'), __('application.users_57f2b181'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($branches as $branch)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $branch->name }}</p><p class="text-xs text-slate-500">{{ $branch->code }}</p></td>
                    <td class="px-4 py-3"><p>{{ $branch->phone ?? '-' }}</p><p class="text-xs text-slate-500">{{ $branch->email ?? '-' }}</p></td>
                    <td class="px-4 py-3">{{ $branch->region ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $branch->users_count }}</td>
                    <td class="px-4 py-3"><span class="{{ $branch->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($branch->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('branches.edit', $branch) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                            <button wire:click="toggleStatus({{ $branch->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $branch->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                            <button wire:click="deleteBranch({{ $branch->id }})" wire:confirm="{{ __('application.delete_this_branch_72d50471') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_branches_found_9fa50b1d') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $branches->links() }}</div>
    </x-card>
</div>
