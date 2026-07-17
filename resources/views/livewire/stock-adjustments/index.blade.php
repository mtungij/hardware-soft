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
    <x-page-header title="Stock Adjustments" description="Request and track stock adjustments by location." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => null]">
        <a href="{{ route('stock-adjustments.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Create Adjustment</a>
        @if (auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']))
            <a href="{{ route('stock-adjustments.approve') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Approve</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="pending">Pending</option>
                <option value="pending_approval">Pending Approval</option>
                <option value="approved">Approved</option>
                <option value="posted">Posted</option>
                <option value="rejected">Rejected</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        @php
            $adjustments = StockAdjustment::query()
                ->with(['product', 'stockLocation', 'requester', 'approver', 'poster'])
                ->withCount('lines')
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['Reference', 'Date', 'Product(s)', 'Location', 'Direction', 'Qty', 'Reason', 'Status', 'Created By', 'Approved By']">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->reference_number ?: 'ADJ-'.$adjustment->id }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_date?->format('d M Y') ?? $adjustment->created_at?->format('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->lines_count > 1 ? $adjustment->lines_count.' products' : $adjustment->product?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ str($adjustment->adjustment_type)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $adjustment->quantity, 2) }}</td>
                    <td class="px-4 py-3">{{ $adjustment->reason }}</td>
                    <td class="px-4 py-3"><span class="{{ in_array($adjustment->status, ['approved', 'posted'], true) ? 'badge-success' : ($adjustment->status === 'rejected' ? 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ str($adjustment->status)->replace('_', ' ')->title() }}</span></td>
                    <td class="px-4 py-3">{{ $adjustment->requester?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->approver?->name ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No stock adjustments found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $adjustments->links() }}</div>
    </x-card>
</div>
