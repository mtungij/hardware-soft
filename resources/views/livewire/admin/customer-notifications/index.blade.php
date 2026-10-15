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
    <x-page-header title="{{ __('application.customer_notifications_a3010997') }}" description="{{ __('application.messages_generated_for_customer_portal_users_0f9a4f5d') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Notifications' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('application.search_customer_message_594137b2') }}">
            <select wire:model.live="type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="all">{{ __('application.all_types_30c8a0fc') }}</option>
                <option value="receipt_approved">{{ __('application.receipt_approved_55a3513b') }}</option>
                <option value="receipt_rejected">{{ __('application.receipt_rejected_00eca2b8') }}</option>
                <option value="deposit_approved">{{ __('application.deposit_approved_2cee0a52') }}</option>
                <option value="deposit_rejected">{{ __('application.deposit_rejected_94ab033b') }}</option>
                <option value="new_debt">{{ __('application.new_debt_acf70a9a') }}</option>
                <option value="new_invoice">{{ __('application.new_invoice_878bae99') }}</option>
                <option value="announcement">{{ __('application.announcement_cf84a98d') }}</option>
                <option value="customer_message">{{ __('application.customer_message_7f99b063') }}</option>
            </select>
        </div>
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.customer_0e85749a'), __('application.type_3deb7456'), __('application.title_768e0c1c'), __('application.priority_886cbff9'), __('application.delivered_eea956cd'), __('application.read_852b438f')]">
            @forelse ($this->notifications as $notification)
                <tr>
                    <td class="px-4 py-3">{{ $notification->created_at->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $notification->customer?->displayName() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($notification->type) }}</td>
                    <td class="px-4 py-3 font-bold">{{ $notification->displayTitle() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($notification->priority ?? 'normal') }}</td>
                    <td class="px-4 py-3">{{ $notification->delivered_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: $notification->created_at->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }}</td>
                    <td class="px-4 py-3">{{ $notification->read_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: __('application.unread_07b032b5') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_customer_notifications_found_2841ddf0') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->notifications->links() }}</div>
    </x-card>
</div>
