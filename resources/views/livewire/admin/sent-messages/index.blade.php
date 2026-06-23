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

$sentMessages = computed(fn () => CustomerNotification::with(['customer', 'account', 'notifiable'])
    ->whereIn('type', ['announcement', 'customer_message'])
    ->when($this->type !== 'all', fn ($query) => $query->where('type', $this->type))
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
        ->where('title', 'like', '%'.$this->search.'%')
        ->orWhere('message', 'like', '%'.$this->search.'%')
        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))))
    ->latest()
    ->paginate(15));

?>

<div>
    <x-page-header title="Sent Messages" description="Delivery and read tracking for portal communications." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Sent Messages' => null]" />

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <x-card><p class="text-xs text-slate-500">Delivered</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Read</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNotNull('read_at')->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Unread</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNull('read_at')->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Urgent</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->where('priority', 'urgent')->count()) }}</p></x-card>
    </div>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="Search sent messages">
            <select wire:model.live="type" class="erp-input">
                <option value="all">All types</option>
                <option value="announcement">Announcements</option>
                <option value="customer_message">Customer Messages</option>
            </select>
        </div>
        <x-table :headers="['Delivered', 'Customer', 'Type', 'Title', 'Priority', 'Read']">
            @forelse ($this->sentMessages as $message)
                <tr>
                    <td class="px-4 py-3">{{ $message->delivered_at?->format('M d, Y H:i') ?: $message->created_at?->format('M d, Y H:i') }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $message->customer?->name }}</td>
                    <td class="px-4 py-3">{{ str($message->type)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3">{{ $message->title }}</td>
                    <td class="px-4 py-3">{{ ucfirst($message->priority ?? 'normal') }}</td>
                    <td class="px-4 py-3">{{ $message->read_at?->format('M d, Y H:i') ?: 'Unread' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No sent messages found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->sentMessages->links() }}</div>
    </x-card>
</div>
