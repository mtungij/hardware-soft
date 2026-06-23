<?php

use App\Models\Branch;
use App\Models\Customer;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'branchFilter' => '', 'typeFilter' => '']);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$toggleStatus = function (int $customerId) {
    abort_unless($this->canManage(), 403);

    $customer = Customer::findOrFail($customerId);
    $customer->update(['status' => $customer->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Customer status updated.');
};

$deleteCustomer = function (int $customerId) {
    abort_unless($this->canManage(), 403);

    Customer::findOrFail($customerId)->delete();
    session()->flash('success', 'Customer deleted.');
};

?>

<div>
    <x-page-header
        title="Customers"
        description="Maintain cash, credit, contractor, and wholesale customer records."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => null]"
    >
        @if ($this->canManage())
            <a href="{{ route('customers.create') }}" wire:navigate data-tour="add-customer" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Customer</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search customers...">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select wire:model.live="typeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All types</option>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
                <option value="contractor">Contractor</option>
                <option value="wholesale">Wholesale</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All branches</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $customers = Customer::query()
                ->with('branch')
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($typeFilter, fn ($query) => $query->where('customer_type', $typeFilter))
                ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table data-tour="customers-list" :headers="['Customer', 'Phone', 'Type', 'Branch', 'Credit Limit', 'Opening Balance', 'Status', 'Actions']">
            @forelse ($customers as $customer)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $customer->name }}</p><p class="text-xs text-slate-500">{{ $customer->email ?? '-' }}</p></td>
                    <td class="px-4 py-3">{{ $customer->phone }}</td>
                    <td class="px-4 py-3 capitalize">{{ $customer->customer_type }}</td>
                    <td class="px-4 py-3">{{ $customer->branch?->name ?? 'Global' }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $customer->credit_limit, 2) }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $customer->opening_balance, 2) }}</td>
                    <td class="px-4 py-3"><span class="{{ $customer->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($customer->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('customers.edit', $customer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                                <button wire:click="toggleStatus({{ $customer->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $customer->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                <button wire:click="deleteCustomer({{ $customer->id }})" wire:confirm="Delete this customer?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">View only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No customers found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $customers->links() }}</div>
    </x-card>
</div>
