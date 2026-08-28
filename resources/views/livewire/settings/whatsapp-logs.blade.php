<?php

use App\Models\Company;
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
state(['companyId' => null, 'status' => '', 'category' => '', 'date' => '']);

mount(function (): void {
    abort_unless(auth()->user()->can('whatsapp.view_logs'), 403);
    $this->companyId = Company::current()?->id;
    abort_unless($this->companyId, 404);
});

with(fn () => ['notifications' => WhatsAppNotification::withoutGlobalScopes()->with(['recipient', 'branch'])
    ->where('company_id', $this->companyId)
    ->when($this->status, fn ($query) => $query->where('status', $this->status))
    ->when($this->category, fn ($query) => $query->where('category', $this->category))
    ->when($this->date, fn ($query) => $query->whereDate('created_at', $this->date))
    ->latest()->paginate(30)]);

$retry = function (int $id, WhatsAppNotificationService $service): void {
    abort_unless(auth()->user()->can('whatsapp.retry_failed'), 403);
    $notification = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($id);
    $service->retry($notification);
};

$cancel = function (int $id): void {
    abort_unless(auth()->user()->can('whatsapp.retry_failed'), 403);
    WhatsAppNotification::withoutGlobalScopes()->where('company_id', $this->companyId)->whereKey($id)->whereIn('status', ['pending', 'queued', 'failed'])->update(['status' => 'cancelled', 'failure_reason' => 'Cancelled by '.auth()->user()->name]);
};

?>
<div>
    <x-page-header title="WhatsApp Notification Log" description="Queued is not sent. This log records the actual delivery lifecycle returned by GOWA." :breadcrumbs="['Dashboard'=>route('dashboard'),'WhatsApp'=>route('settings.whatsapp'),'Log'=>null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3"><select wire:model.live="status" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All statuses</option>@foreach(['pending','queued','sending','sent','failed','cancelled','suppressed'] as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</select><input type="text" wire:model.live.debounce.300ms="category" placeholder="Category" class="rounded-lg border-slate-200 dark:bg-navy-950"><input type="date" wire:model.live="date" class="rounded-lg border-slate-200 dark:bg-navy-950"></div>
        <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b dark:border-slate-700"><th class="p-3">Created</th><th class="p-3">Type / Reference</th><th class="p-3">Recipient</th><th class="p-3">Device</th><th class="p-3">Status</th><th class="p-3">Attempts</th><th class="p-3">Queued</th><th class="p-3">Sent</th><th class="p-3">Failure / Suppression</th><th class="p-3"></th></tr></thead><tbody>@forelse($notifications as $row)<tr class="border-b align-top dark:border-slate-800"><td class="p-3 whitespace-nowrap">{{ $row->created_at->format('d M H:i') }}</td><td class="p-3"><div class="font-bold">{{ str($row->notification_type)->replace('_',' ')->title() }}</div><div class="max-w-xs truncate text-xs text-slate-500">{{ $row->idempotency_key }}</div></td><td class="p-3">{{ $row->recipient?->name ?: $row->phone }}<div class="text-xs text-slate-500">{{ $row->branch?->name }}</div></td><td class="p-3 font-mono text-xs">{{ $row->device_id ?: '-' }}</td><td class="p-3 font-bold">{{ ucfirst($row->status) }}</td><td class="p-3">{{ $row->attempts }}</td><td class="p-3 whitespace-nowrap">{{ $row->queued_at?->format('d M H:i') ?: '-' }}</td><td class="p-3 whitespace-nowrap">{{ $row->sent_at?->format('d M H:i') ?: '-' }}</td><td class="p-3 max-w-xs text-xs text-red-600">{{ $row->failure_reason }}</td><td class="p-3"><div class="flex gap-2">@if(in_array($row->status,['failed','suppressed']))<button wire:click="retry({{ $row->id }})" class="text-xs font-black text-build-orange">Retry</button>@endif @if(in_array($row->status,['pending','queued','failed']))<button wire:click="cancel({{ $row->id }})" class="text-xs font-black text-red-600">Cancel</button>@endif</div></td></tr>@empty<tr><td colspan="10" class="p-8 text-center text-slate-500">No WhatsApp notifications found.</td></tr>@endforelse</tbody></table></div>
        <div class="mt-4">{{ $notifications->links() }}</div>
    </x-card>
</div>
