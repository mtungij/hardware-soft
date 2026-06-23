<?php

use App\Models\Announcement;
use App\Models\AnnouncementCustomer;
use App\Models\CustomerMessage;
use App\Models\CustomerNotification;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

state(['filter' => 'all', 'selectedId' => null]);

$account = computed(fn () => auth('customer')->user());

$notifications = computed(fn () => CustomerNotification::query()
    ->with('notifiable')
    ->where('customer_id', $this->account->customer_id)
    ->when($this->filter === 'unread', fn ($query) => $query->whereNull('read_at'))
    ->when($this->filter === 'read', fn ($query) => $query->whereNotNull('read_at'))
    ->when($this->filter === 'announcements', fn ($query) => $query->where('type', 'announcement'))
    ->when($this->filter === 'promotions', fn ($query) => $query->where('type', 'announcement')->whereIn('priority', ['low', 'normal']))
    ->when($this->filter === 'important', fn ($query) => $query->whereIn('priority', ['high', 'urgent']))
    ->latest()
    ->paginate(10));

$selectedNotification = computed(fn () => $this->selectedId
    ? CustomerNotification::with('notifiable')->where('customer_id', $this->account->customer_id)->find($this->selectedId)
    : null);

$openNotification = function (int $id) {
    $notification = CustomerNotification::with('notifiable')->where('customer_id', $this->account->customer_id)->findOrFail($id);

    if (! $notification->read_at) {
        $notification->forceFill(['read_at' => now()])->save();

        if ($notification->notifiable instanceof Announcement) {
            AnnouncementCustomer::query()
                ->where('announcement_id', $notification->notifiable->id)
                ->where('customer_id', $this->account->customer_id)
                ->update(['is_read' => true, 'read_at' => now()]);
        }

        if ($notification->notifiable instanceof CustomerMessage) {
            $notification->notifiable->forceFill(['read_at' => now()])->save();
        }
    }

    $this->selectedId = $id;
    $this->dispatch('open-modal', 'customer-notification-details');
};

$markAllRead = function () {
    CustomerNotification::where('customer_id', $this->account->customer_id)->whereNull('read_at')->update(['read_at' => now()]);
    AnnouncementCustomer::where('customer_id', $this->account->customer_id)->where('is_read', false)->update(['is_read' => true, 'read_at' => now()]);
    CustomerMessage::where('customer_id', $this->account->customer_id)->whereNull('read_at')->update(['read_at' => now()]);
    session()->flash('success', 'Notifications marked as read.');
};

$priorityClass = fn (?string $priority) => match ($priority) {
    'urgent' => 'bg-red-500/10 text-red-500',
    'high' => 'bg-amber-500/10 text-amber-500',
    'low' => 'bg-slate-500/10 text-slate-500',
    default => 'bg-cyan-500/10 text-cyan-500',
};

$sourceAttachment = function (?CustomerNotification $notification): ?string {
    $source = $notification?->notifiable;

    return $source?->attachment ?? null;
};

$sourceImage = function (?CustomerNotification $notification): ?string {
    $source = $notification?->notifiable;

    return $source instanceof Announcement ? $source->image : null;
};

$senderName = fn (?CustomerNotification $notification): string => $notification?->notifiable?->creator?->name
    ?? $notification?->notifiable?->sender?->name
    ?? 'Hardex';

?>

<div>
    <x-page-header :title="__('messages.nav.notifications')" description="Notification Center" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.nav.notifications') => null]">
        <button type="button" wire:click="markAllRead" class="erp-btn-secondary">{{ __('messages.notifications.mark_all_read') }}</button>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['all' => __('messages.notifications.all'), 'unread' => __('messages.notifications.unread'), 'read' => __('messages.notifications.read'), 'announcements' => __('messages.notifications.announcements'), 'promotions' => __('messages.notifications.promotions'), 'important' => __('messages.notifications.important')] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" class="rounded-lg border px-3 py-2 text-sm font-semibold {{ $filter === $value ? 'border-build-orange bg-build-orange text-white' : 'border-slate-200 dark:border-slate-700' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($this->notifications as $notification)
            <x-card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            @if (! $notification->read_at)
                                <span class="h-2.5 w-2.5 rounded-full bg-build-orange"></span>
                            @endif
                            <p class="font-black text-navy-900 dark:text-white">{{ $notification->title }}</p>
                            <span class="rounded-full px-2 py-1 text-xs font-bold {{ $this->priorityClass($notification->priority) }}">{{ ucfirst($notification->priority ?? 'normal') }}</span>
                        </div>
                        <p class="mt-1 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{{ $notification->message }}</p>
                        <p class="mt-2 text-xs font-semibold text-slate-400">{{ $notification->created_at?->format('M d, Y H:i') }}</p>
                    </div>
                    <button type="button" wire:click="openNotification({{ $notification->id }})" class="erp-btn-primary whitespace-nowrap">{{ __('messages.notifications.read_more') }}</button>
                </div>
            </x-card>
        @empty
            <x-card>
                <p class="py-6 text-center text-sm text-slate-500">{{ __('messages.notifications.empty') }}</p>
            </x-card>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->notifications->links() }}</div>

    <x-modal name="customer-notification-details" maxWidth="2xl">
        @if ($this->selectedNotification)
            <div class="p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $this->selectedNotification->title }}</h2>
                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $this->senderName($this->selectedNotification) }} · {{ $this->selectedNotification->created_at?->format('M d, Y H:i') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'customer-notification-details')" class="erp-btn-secondary px-2 py-1">Close</button>
                </div>
                @if ($this->sourceImage($this->selectedNotification))
                    <img src="{{ asset('storage/'.$this->sourceImage($this->selectedNotification)) }}" class="mt-4 max-h-72 w-full rounded-xl object-cover" alt="">
                @endif
                <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $this->selectedNotification->notifiable?->message ?? $this->selectedNotification->message }}</p>
                @if ($this->sourceAttachment($this->selectedNotification))
                    <a href="{{ asset('storage/'.$this->sourceAttachment($this->selectedNotification)) }}" target="_blank" class="mt-5 inline-flex rounded-lg bg-build-orange px-4 py-2 text-sm font-semibold text-white">{{ __('messages.notifications.download_attachment') }}</a>
                @endif
                <p class="mt-4 text-xs font-semibold text-slate-500">{{ __('messages.notifications.marked_read') }}: {{ $this->selectedNotification->read_at?->format('M d, Y H:i') }}</p>
            </div>
        @endif
    </x-modal>
</div>
