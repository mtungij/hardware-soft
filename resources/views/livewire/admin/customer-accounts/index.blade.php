<?php

use App\Models\CustomerAccount;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'status' => 'all']);

$accounts = computed(function () {
    return CustomerAccount::with(['customer.branch'])
        ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')->orWhere('phone', 'like', '%'.$this->search.'%')))
        ->latest()
        ->paginate(12);
});

$approve = function (CustomerAccount $account) {
    $account->update(['status' => 'active', 'approved_at' => now(), 'approved_by' => auth()->id()]);
    session()->flash('success', 'Customer account approved.');
};

$suspend = function (CustomerAccount $account) {
    $account->update(['status' => 'suspended']);
    session()->flash('success', 'Customer account suspended.');
};

?>

<div>
    <x-page-header title="Customer Accounts" description="Approve and manage customer portal accounts." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Accounts' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Search customers">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="all">All statuses</option><option value="pending">Pending</option><option value="active">Active</option><option value="suspended">Suspended</option>
            </select>
        </div>
        <x-table :headers="['Customer', 'Contact', 'Branch', 'Status', 'Created', 'Actions']">
            @forelse ($this->accounts as $account)
                <tr>
                    <td class="px-4 py-3"><p class="font-black">{{ $account->name }}</p><p class="text-xs text-slate-500">{{ $account->customer?->name }}</p></td>
                    <td class="px-4 py-3">{{ $account->email }}<br><span class="text-xs text-slate-500">{{ $account->phone ?: '-' }}</span></td>
                    <td class="px-4 py-3">{{ $account->customer?->branch?->name ?: '-' }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($account->status === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ str($account->status)->title() }}</span></td>
                    <td class="px-4 py-3">{{ $account->created_at->format('M d, Y') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.customer-accounts.show', $account) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black dark:border-slate-700">View</a>
                            @if ($account->status !== 'active')<button wire:click="approve({{ $account->id }})" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-black text-white">Approve</button>@endif
                            @if ($account->status !== 'suspended')<button wire:click="suspend({{ $account->id }})" wire:confirm="Suspend this account?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-black text-white">Suspend</button>@endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No customer accounts found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->accounts->links() }}</div>
    </x-card>
</div>
