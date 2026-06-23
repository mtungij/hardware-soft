<?php

use App\Models\Branch;
use App\Models\Supplier;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'branchFilter' => '']);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$toggleStatus = function (int $supplierId) {
    abort_unless($this->canManage(), 403);

    $supplier = Supplier::findOrFail($supplierId);
    $supplier->update(['status' => $supplier->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Supplier status updated.');
};

$deleteSupplier = function (int $supplierId) {
    abort_unless($this->canManage(), 403);

    Supplier::findOrFail($supplierId)->delete();
    session()->flash('success', 'Supplier deleted.');
};

?>

<div>
    <x-page-header
        title="Suppliers"
        description="Maintain supplier master records for future purchases and balances."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Suppliers' => null]"
    >
        @if ($this->canManage())
            <a href="{{ route('suppliers.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Supplier</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search suppliers...">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All branches</option>
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

        <x-table :headers="['Supplier', 'Contact', 'Region', 'Branch', 'Opening Balance', 'Status', 'Actions']">
            @forelse ($suppliers as $supplier)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $supplier->name }}</p><p class="text-xs text-slate-500">{{ $supplier->email ?? '-' }}</p></td>
                    <td class="px-4 py-3"><p>{{ $supplier->contact_person ?? '-' }}</p><p class="text-xs text-slate-500">{{ $supplier->phone }}</p></td>
                    <td class="px-4 py-3">{{ $supplier->region ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $supplier->branch?->name ?? 'Global' }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $supplier->opening_balance, 2) }}</td>
                    <td class="px-4 py-3"><span class="{{ $supplier->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($supplier->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('suppliers.edit', $supplier) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                                <button wire:click="toggleStatus({{ $supplier->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $supplier->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                <button wire:click="deleteSupplier({{ $supplier->id }})" wire:confirm="Delete this supplier?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">View only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No suppliers found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $suppliers->links() }}</div>
    </x-card>
</div>
