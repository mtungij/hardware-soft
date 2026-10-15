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
    <x-page-header title="{{ __('application.sent_messages_6dfe35ce') }}" description="{{ __('application.delivery_and_read_tracking_for_portal_communications_afc23e22') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Sent Messages' => null]" />

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <x-card><p class="text-xs text-slate-500">{{ __('application.delivered_eea956cd') }}</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">{{ __('application.read_852b438f') }}</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNotNull('read_at')->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">{{ __('application.unread_07b032b5') }}</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->whereNull('read_at')->count()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">{{ __('application.urgent_ecb26f46') }}</p><p class="text-2xl font-bold">{{ number_format(CustomerNotification::whereIn('type', ['announcement', 'customer_message'])->where('priority', 'urgent')->count()) }}</p></x-card>
    </div>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="{{ __('application.search_sent_messages_a7800559') }}">
            <select wire:model.live="type" class="erp-input">
                <option value="all">{{ __('application.all_types_30c8a0fc') }}</option>
                <option value="announcement">{{ __('application.announcements_06b01c44') }}</option>
                <option value="customer_message">{{ __('application.customer_messages_024c7815') }}</option>
            </select>
        </div>
        <x-table :headers="[__('application.delivered_eea956cd'), __('application.customer_0e85749a'), __('application.type_3deb7456'), __('application.title_768e0c1c'), __('application.priority_886cbff9'), __('application.read_852b438f')]">
            @forelse ($this->sentMessages as $message)
                <tr>
                    <td class="px-4 py-3">{{ $message->delivered_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: $message->created_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $message->customer?->displayName() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($message->type) }}</td>
                    <td class="px-4 py-3">{{ $message->title }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($message->priority ?? 'normal') }}</td>
                    <td class="px-4 py-3">{{ $message->read_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: __('application.unread_07b032b5') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_sent_messages_found_f63599d5') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->sentMessages->links() }}</div>
    </x-card>
</div>
