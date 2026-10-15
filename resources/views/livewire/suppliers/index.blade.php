<?php

use App\Models\Branch;
use App\Models\Supplier;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : '')]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$toggleStatus = function (int $supplierId) {
    abort_unless($this->canManage(), 403);

    $supplier = Supplier::findOrFail($supplierId);
    $supplier->update(['status' => $supplier->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.supplier_status_updated_fb7993ce'));
};

$deleteSupplier = function (int $supplierId) {
    abort_unless($this->canManage(), 403);

    Supplier::findOrFail($supplierId)->delete();
    session()->flash('success', __('application.supplier_deleted_c3e9ddb9'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.suppliers_6f76bb2b') }}"
        description="{{ __('application.maintain_supplier_master_records_for_future_purchases_and_balances_d75ef96b') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Suppliers' => null]"
    >
        <x-export-actions export="tables.suppliers" :params="compact('search', 'statusFilter', 'branchFilter')" />
        @if ($this->canManage())
            <a href="{{ route('suppliers.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_supplier_6af8e34a') }}</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_suppliers_d4778f9d') }}">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="active">{{ __('application.active_a733b809') }}</option>
                <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                <option value="">{{ __('application.all_branches_25c23746') }}</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $suppliers = Supplier::query()
                ->with('branch')
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="[__('application.supplier_55edd462'), __('terminology.contact_b37456c4'), __('application.region_0f217179'), __('application.branch_1627510b'), __('application.opening_balance_1a1a6305'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($suppliers as $supplier)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $supplier->name }}</p><p class="text-xs text-slate-500">{{ $supplier->email ?? '-' }}</p></td>
                    <td class="px-4 py-3"><p>{{ $supplier->contact_person ?? '-' }}</p><p class="text-xs text-slate-500">{{ $supplier->phone }}</p></td>
                    <td class="px-4 py-3">{{ $supplier->region ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $supplier->branch?->name ?? __('terminology.global_5f1184f7') }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($supplier->opening_balance) }}</td>
                    <td class="px-4 py-3"><span class="{{ $supplier->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($supplier->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('suppliers.edit', $supplier) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                                <button wire:click="toggleStatus({{ $supplier->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $supplier->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                <button wire:click="deleteSupplier({{ $supplier->id }})" wire:confirm="{{ __('application.delete_this_supplier_a8ec0655') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_suppliers_found_9e609581') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $suppliers->links() }}</div>
    </x-card>
</div>
