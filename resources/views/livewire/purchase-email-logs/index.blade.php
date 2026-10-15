<?php

use App\Models\PurchaseEmailLog;
use App\Models\Supplier;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'supplierFilter' => '', 'dateFrom' => '', 'dateTo' => '']);

mount(function () {
    $this->search = request('search', $this->search);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
    $this->supplierFilter = request('supplierFilter', $this->supplierFilter);
    $this->dateFrom = request('dateFrom', $this->dateFrom);
    $this->dateTo = request('dateTo', $this->dateTo);
});

?>

<div>
    <x-page-header title="{{ __('application.purchase_email_report_a39de8db') }}" description="{{ __('application.track_outgoing_purchase_order_emails_recipients_status_and_failures_db0e0221') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchase Email Logs' => null]">
        <x-export-actions export="tables.email-logs" :params="['search' => $search, 'status' => $statusFilter, 'supplier_id' => $supplierFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo]" />
    </x-page-header>

    @php
        $logs = PurchaseEmailLog::query()
            ->with(['purchase.supplier', 'sentBy'])
            ->when($search, fn ($query) => $query->where('recipient_email', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")->orWhereHas('purchase', fn ($purchase) => $purchase->where('reference_number', 'like', "%{$search}%")))
            ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
            ->when($supplierFilter, fn ($query) => $query->whereHas('purchase', fn ($purchase) => $purchase->where('supplier_id', $supplierFilter)))
            ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest()
            ->paginate(12);
    @endphp

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="{{ __('application.search_purchase_recipient_ab2d8ae5') }}">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="pending">{{ __('application.pending_96f608c1') }}</option>
                <option value="sent">{{ __('application.sent_35f49dcf') }}</option>
                <option value="failed">{{ __('application.failed_09fef5d8') }}</option>
            </select>
            <select wire:model.live="supplierFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                <option value="">{{ __('application.all_suppliers_471f0ea6') }}</option>
                @foreach (Supplier::orderBy('name')->get() as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
            <input wire:model.live="dateTo" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
        </div>

        <x-table :headers="[__('application.purchase_number_bef19154'), __('application.supplier_55edd462'), __('application.recipient_90343260'), __('application.status_bae7d5be'), __('application.sent_by_a027e031'), __('application.sent_date_5feb6f43'), __('application.error_7f2f6a15')]">
            @forelse ($logs as $log)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $log->purchase?->reference_number }}</td>
                    <td class="px-4 py-3">{{ $log->purchase?->supplier?->name }}</td>
                    <td class="px-4 py-3">{{ $log->recipient_email }}</td>
                    <td class="px-4 py-3"><span class="{{ $log->status === 'sent' ? 'badge-success' : ($log->status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::status($log->status) }}</span></td>
                    <td class="px-4 py-3">{{ $log->sentBy?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $log->sent_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?? '-' }}</td>
                    <td class="max-w-xs truncate px-4 py-3">{{ \App\Support\UiText::feedback($log->error_message) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">{{ __('application.no_purchase_email_logs_found_08f7ae9c') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $logs->links() }}</div>
    </x-card>
</div>
