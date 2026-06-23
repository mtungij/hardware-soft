<?php

use App\Models\CustomerNotification;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

$notifications = computed(fn () => CustomerNotification::query()
    ->where('customer_id', auth('customer')->user()->customer_id)
    ->latest()
    ->paginate(10));

$titleFor = fn (CustomerNotification $notification) => __("messages.notifications.$notification->type") === "messages.notifications.$notification->type"
    ? $notification->title
    : __("messages.notifications.$notification->type");

?>

<div>
    <x-page-header :title="__('messages.nav.notifications')" :description="__('messages.customer_portal')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.nav.notifications') => null]" />

    <div class="space-y-3">
        @forelse ($this->notifications as $notification)
            <x-card>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="font-black text-navy-900 dark:text-white">{{ $this->titleFor($notification) }}</p>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $notification->message }}</p>
                    </div>
                    <p class="text-xs font-semibold text-slate-400">{{ $notification->created_at?->format('M d, Y H:i') }}</p>
                </div>
            </x-card>
        @empty
            <x-card>
                <p class="py-6 text-center text-sm text-slate-500">{{ __('messages.notifications.empty') }}</p>
            </x-card>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->notifications->links() }}</div>
</div>
