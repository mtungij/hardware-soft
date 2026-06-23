<?php

use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'fromFilter' => '', 'toFilter' => '', 'dateFrom' => '', 'dateTo' => '']);

$canCreate = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canCancel = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);

$completeTransfer = function (int $transferId, InventoryService $inventory) {
    abort_unless($this->canManage(), 403);

    $inventory->completeStockTransfer($transferId, auth()->id());
    session()->flash('success', 'Stock transfer completed.');
};

$cancelTransfer = function (int $transferId) {
    abort_unless($this->canCancel(), 403);

    $transfer = StockTransfer::findOrFail($transferId);

    if ($transfer->status === 'completed') {
        session()->flash('error', 'Completed transfers cannot be cancelled.');
        return;
    }

    $transfer->update([
        'status' => 'cancelled',
        'cancelled_by' => auth()->id(),
        'cancelled_at' => now(),
    ]);

    session()->flash('success', 'Stock transfer cancelled.');
};

?>

<div>
    <x-page-header title="Stock Transfers" description="Transfer stock from Main Store to Dispensing Area." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => null]">
        @if ($this->canCreate())
            <a href="{{ route('stock-transfers.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Transfer</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-6">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search transfer...">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <select wire:model.live="fromFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">From any</option>
                @foreach (StockLocation::orderBy('name')->get() as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="toFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">To any</option>
                @foreach (StockLocation::orderBy('name')->get() as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="dateTo" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>

        @php
            $transfers = StockTransfer::query()
                ->with(['fromLocation', 'toLocation', 'createdBy'])
                ->withCount('items')
                ->withSum('items', 'quantity')
                ->when($search, fn ($query) => $query->where('transfer_number', 'like', "%{$search}%"))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($fromFilter, fn ($query) => $query->where('from_location_id', $fromFilter))
                ->when($toFilter, fn ($query) => $query->where('to_location_id', $toFilter))
                ->when($dateFrom, fn ($query) => $query->whereDate('transfer_date', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('transfer_date', '<=', $dateTo))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['Transfer #', 'Date', 'From', 'To', 'Items', 'Quantity', 'Created By', 'Status', 'Actions']">
            @forelse ($transfers as $transfer)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 font-black">{{ $transfer->transfer_number }}</td>
                    <td class="px-4 py-3">{{ $transfer->transfer_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $transfer->fromLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $transfer->toLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $transfer->items_count }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $transfer->items_sum_quantity, 2) }}</td>
                    <td class="px-4 py-3">{{ $transfer->createdBy?->name }}</td>
                    <td class="px-4 py-3"><span class="{{ $transfer->status === 'completed' ? 'badge-success' : ($transfer->status === 'cancelled' ? 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($transfer->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('stock-transfers.show', $transfer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">View</a>
                            @if ($transfer->canBeModified() && $this->canManage())
                                <a href="{{ route('stock-transfers.edit', $transfer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                                <button wire:click="completeTransfer({{ $transfer->id }})" class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">Complete</button>
                            @endif
                            @if ($transfer->status !== 'completed' && $this->canCancel())
                                <button wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Cancel this transfer?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Cancel</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No stock transfers found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $transfers->links() }}</div>
    </x-card>
</div>
