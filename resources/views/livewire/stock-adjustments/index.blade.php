<?php

use App\Models\StockAdjustment;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['statusFilter' => '']);

?>

<div>
    <x-page-header title="Stock Adjustments" description="Request and track Main Store stock adjustments." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => null]">
        <a href="{{ route('stock-adjustments.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Create Adjustment</a>
        @if (auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']))
            <a href="{{ route('stock-adjustments.approve') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Approve</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>

        @php
            $adjustments = StockAdjustment::query()
                ->with(['product', 'stockLocation', 'requester', 'approver'])
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['Product', 'Location', 'Type', 'Qty', 'Reason', 'Status', 'Requested By', 'Approved By']">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->product?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_type }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $adjustment->quantity, 2) }}</td>
                    <td class="px-4 py-3">{{ $adjustment->reason }}</td>
                    <td class="px-4 py-3"><span class="{{ $adjustment->status === 'approved' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($adjustment->status) }}</span></td>
                    <td class="px-4 py-3">{{ $adjustment->requester?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->approver?->name ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No stock adjustments found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $adjustments->links() }}</div>
    </x-card>
</div>
