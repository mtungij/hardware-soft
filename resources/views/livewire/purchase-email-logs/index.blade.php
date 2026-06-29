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
    <x-page-header title="Purchase Email Report" description="Track outgoing purchase order emails, recipients, status, and failures." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchase Email Logs' => null]" />

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
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Search purchase/recipient">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="sent">Sent</option>
                <option value="failed">Failed</option>
            </select>
            <select wire:model.live="supplierFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                <option value="">All suppliers</option>
                @foreach (Supplier::orderBy('name')->get() as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
            <input wire:model.live="dateTo" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
        </div>

        <x-table :headers="['Purchase Number', 'Supplier', 'Recipient', 'Status', 'Sent By', 'Sent Date', 'Error']">
            @forelse ($logs as $log)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $log->purchase?->reference_number }}</td>
                    <td class="px-4 py-3">{{ $log->purchase?->supplier?->name }}</td>
                    <td class="px-4 py-3">{{ $log->recipient_email }}</td>
                    <td class="px-4 py-3"><span class="{{ $log->status === 'sent' ? 'badge-success' : ($log->status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($log->status) }}</span></td>
                    <td class="px-4 py-3">{{ $log->sentBy?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $log->sent_at?->format('M d, Y H:i') ?? '-' }}</td>
                    <td class="max-w-xs truncate px-4 py-3">{{ $log->error_message ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No purchase email logs found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $logs->links() }}</div>
    </x-card>
</div>
