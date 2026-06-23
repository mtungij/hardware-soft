<?php

use App\Models\CustomerDeposit;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['status' => 'pending', 'search' => '']);

$deposits = computed(function () {
    return CustomerDeposit::with(['customer', 'account'])
        ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))->orWhere('reference_number', 'like', '%'.$this->search.'%')))
        ->latest()
        ->paginate(12);
});

?>

<div>
    <x-page-header title="Customer Deposits" description="Approve advance deposit receipts." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Deposits' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Search customer/reference">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="pending">Pending</option><option value="approved">Approved</option><option value="partial">Partial</option><option value="used">Used</option><option value="rejected">Rejected</option><option value="all">All</option>
            </select>
        </div>
        <x-table :headers="['Date', 'Customer', 'Reference', 'Amount', 'Balance', 'Status', 'Actions']">
            @forelse ($this->deposits as $deposit)
                <tr>
                    <td class="px-4 py-3">{{ $deposit->created_at->format('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $deposit->customer?->name }}</td>
                    <td class="px-4 py-3">{{ $deposit->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $deposit->amount, 2) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $deposit->balance_amount, 2) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ in_array($deposit->status, ['approved', 'partial']) ? 'bg-emerald-100 text-emerald-700' : ($deposit->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ str($deposit->status)->title() }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('admin.customer-deposits.show', $deposit) }}" wire:navigate class="text-sm font-black text-build-orange">Review</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No deposits found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->deposits->links() }}</div>
    </x-card>
</div>
