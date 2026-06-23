<?php

use App\Models\CustomerNotification;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'type' => 'all']);

$notifications = computed(function () {
    return CustomerNotification::with(['customer', 'account'])
        ->when($this->type !== 'all', fn ($query) => $query->where('type', $this->type))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
            ->where('title', 'like', '%'.$this->search.'%')
            ->orWhere('message', 'like', '%'.$this->search.'%')
            ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))))
        ->latest()
        ->paginate(15);
});

?>

<div>
    <x-page-header title="Customer Notifications" description="Messages generated for customer portal users." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Notifications' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Search customer/message">
            <select wire:model.live="type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="all">All types</option>
                <option value="receipt_approved">Receipt Approved</option>
                <option value="receipt_rejected">Receipt Rejected</option>
                <option value="deposit_approved">Deposit Approved</option>
                <option value="deposit_rejected">Deposit Rejected</option>
                <option value="new_debt">New Debt</option>
                <option value="new_invoice">New Invoice</option>
            </select>
        </div>
        <x-table :headers="['Date', 'Customer', 'Type', 'Title', 'Message', 'Read']">
            @forelse ($this->notifications as $notification)
                <tr>
                    <td class="px-4 py-3">{{ $notification->created_at->format('M d, Y H:i') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $notification->customer?->name }}</td>
                    <td class="px-4 py-3">{{ str($notification->type)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3 font-bold">{{ $notification->title }}</td>
                    <td class="px-4 py-3">{{ $notification->message }}</td>
                    <td class="px-4 py-3">{{ $notification->read_at?->format('M d, Y H:i') ?: 'Unread' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No customer notifications found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->notifications->links() }}</div>
    </x-card>
</div>
