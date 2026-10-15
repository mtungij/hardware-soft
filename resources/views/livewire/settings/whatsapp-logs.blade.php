<?php

use App\Models\Company;
use App\Support\WhatsAppCategories;
use App\Models\WhatsAppNotification;
use App\Services\WhatsAppNotificationService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;
use function Livewire\Volt\with;

layout('layouts.app');
uses([WithPagination::class]);
state(['companyId' => null])->locked();
state(['status' => '', 'category' => '', 'date' => '']);

mount(function (): void {
    abort_unless(auth()->user()->can('whatsapp.view_logs'), 403);
    $this->companyId = Company::current()?->id;
    abort_unless($this->companyId, 404);
});

with(fn () => ['notifications' => WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->with(['recipient', 'branch'])
    ->where('company_id', $this->companyId)
    ->when($this->status, fn ($query) => $query->where('status', $this->status))
    ->when($this->category, fn ($query) => $query->where('category', $this->category))
    ->when($this->date, fn ($query) => $query->whereDate('created_at', $this->date))
    ->latest()->paginate(30)]);

$retry = function (int $id, WhatsAppNotificationService $service): void {
    abort_unless(auth()->user()->can('whatsapp.retry_failed'), 403);
    $notification = WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->findOrFail($id);
    $service->retry($notification);
};

$cancel = function (int $id): void {
    abort_unless(auth()->user()->can('whatsapp.retry_failed'), 403);
    WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->whereKey($id)->whereIn('status', ['pending', 'queued', 'failed'])->update(['status' => 'cancelled', 'failure_reason' => 'Cancelled by '.auth()->user()->name]);
};

?>
<div>
    <x-page-header title="{{ __('application.whatsapp_notification_log_10edabe2') }}" description="{{ __('application.queued_is_not_sent_this_log_records_the_actual_delivery_lifecycle_returned__15f92192') }}" :breadcrumbs="['Dashboard'=>route('dashboard'),'WhatsApp'=>route('settings.whatsapp'),'Log'=>null]" />
    @if (WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)->where('status', 'queued')->where('attempts', 0)->where('queued_at', '<=', now()->subMinutes(5))->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))->exists())
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100" role="status">
            {{ __('application.some_notifications_have_waited_over_five_minutes_without_a_delivery_attempt_0a1f963d') }}
        </div>
    @endif
    <p class="mb-3 text-sm text-slate-500">{{ __('application.queued_waiting_for_processing_sending_delivery_attempt_in_progress_sent_gow_54fb6ebd') }}</p>
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3"><select wire:model.live="status" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_statuses_6405179d') }}</option>@foreach(['pending','queued','sending','sent','failed','cancelled','suppressed'] as $value)<option value="{{ $value }}">{{ \App\Support\UiText::label($value) }}</option>@endforeach</select><select wire:model.live="category" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_categories_060be00f') }}</option>@foreach(WhatsAppCategories::available($companyId) + ['system' => 'System'] as $value => $label)<option value="{{ $value }}">{{ \App\Support\UiText::translate($label) }}</option>@endforeach</select><input type="date" wire:model.live="date" class="rounded-lg border-slate-200 dark:bg-navy-950"></div>
        <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b dark:border-slate-700"><th class="p-3">{{ __('application.created_accf40c8') }}</th><th class="p-3">{{ __('application.type_reference_47fe983d') }}</th><th class="p-3">{{ __('application.recipient_90343260') }}</th><th class="p-3">{{ __('application.device_a5a74a6d') }}</th><th class="p-3">{{ __('application.status_bae7d5be') }}</th><th class="p-3">{{ __('application.attempts_5a29585e') }}</th><th class="p-3">{{ __('application.queued_6a599877') }}</th><th class="p-3">{{ __('application.sent_35f49dcf') }}</th><th class="p-3">{{ __('application.failure_suppression_aa8e3f07') }}</th><th class="p-3"></th></tr></thead><tbody>@forelse($notifications as $row)<tr class="border-b align-top dark:border-slate-800"><td class="p-3 whitespace-nowrap">{{ $row->created_at->locale(app()->getLocale())->translatedFormat('d M H:i') }}</td><td class="p-3"><div class="font-bold">{{ $row->notification_type === 'goods_received_grn' ? __('application.goods_received_grn_41e4ab9a') : \App\Support\UiText::notificationType($row->notification_type) }}</div><div class="max-w-xs truncate text-xs text-slate-500">{{ $row->metadata['grn_number'] ?? $row->metadata['po_number'] ?? $row->idempotency_key }}</div></td><td class="p-3">{{ $row->recipient?->name ?: $row->phone }}<div class="text-xs text-slate-500">{{ $row->branch?->name }}</div></td><td class="p-3 font-mono text-xs">{{ $row->device_id ?: '-' }}</td><td class="p-3 font-bold">{{ \App\Support\UiText::status($row->status) }}</td><td class="p-3">{{ $row->attempts }}</td><td class="p-3 whitespace-nowrap">{{ $row->queued_at?->locale(app()->getLocale())->translatedFormat('d M H:i') ?: '-' }}</td><td class="p-3 whitespace-nowrap">{{ $row->sent_at?->locale(app()->getLocale())->translatedFormat('d M H:i') ?: '-' }}</td><td class="p-3 max-w-xs text-xs text-red-600">{{ \App\Support\UiText::feedback($row->failure_reason) }}</td><td class="p-3"><div class="flex gap-2">@if(in_array($row->status,['failed','suppressed']))<button wire:click="retry({{ $row->id }})" class="text-xs font-black text-build-orange">{{ __('application.retry_9f5cd8a2') }}</button>@endif @if(in_array($row->status,['pending','queued','failed']))<button wire:click="cancel({{ $row->id }})" class="text-xs font-black text-red-600">{{ __('application.cancel_77dfd213') }}</button>@endif</div></td></tr>@empty<tr><td colspan="10" class="p-8 text-center text-slate-500">{{ __('application.no_whatsapp_notifications_found_abaa8c46') }}</td></tr>@endforelse</tbody></table></div>
        <div class="mt-4">{{ $notifications->links() }}</div>
    </x-card>
</div>
