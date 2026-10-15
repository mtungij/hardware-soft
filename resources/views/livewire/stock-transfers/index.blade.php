<?php

use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['search' => '', 'statusFilter' => '', 'fromFilter' => '', 'toFilter' => '', 'dateFrom' => '', 'dateTo' => '']);

mount(function () {
    $this->search = request('search', $this->search);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
    $this->fromFilter = request('fromFilter', $this->fromFilter);
    $this->toFilter = request('toFilter', $this->toFilter);
    $this->dateFrom = request('dateFrom', $this->dateFrom);
    $this->dateTo = request('dateTo', $this->dateTo);
});

$canCreate = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canCancel = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);

$completeTransfer = function (int $transferId, InventoryService $inventory) {
    abort_unless($this->canManage(), 403);

    $inventory->completeStockTransfer($transferId, auth()->id());
    session()->flash('success', __('application.stock_transfer_completed_8ca94dc0'));
};

$cancelTransfer = function (int $transferId) {
    abort_unless($this->canCancel(), 403);

    \Illuminate\Support\Facades\DB::transaction(function () use ($transferId) {
        $transfer = StockTransfer::query()->where('company_id', auth()->user()->company_id)
            ->whereKey($transferId)->lockForUpdate()->firstOrFail();
        app(\App\Services\StockTransferNoteService::class)->authorize($transfer, auth()->user());

        if ($transfer->status !== 'draft') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'transfer' => __('application.only_draft_transfers_can_be_cancelled_here_use_the_transfer_details_to_reve_d605ea13'),
            ]);
        }

        $transfer->update([
            'status' => 'cancelled',
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);
    });

    session()->flash('success', __('application.stock_transfer_cancelled_ec32e911'));
};

?>

<div>
    <x-page-header title="{{ __('application.stock_transfers_5b3d4988') }}" description="{{ __('application.transfer_stock_between_authorized_internal_locations_f33f96ba') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => null]">
        <x-export-actions export="tables.stock-transfers" :params="['search' => $search, 'status' => $statusFilter, 'from_location_id' => $fromFilter, 'to_location_id' => $toFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo]" />
        @if ($this->canCreate())
            <a href="{{ route('stock-transfers.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_transfer_c86814b6') }}</a>
        @endif
    </x-page-header>

    @error('transfer') <p role="alert" class="mb-4 text-red-600">{{ $message }}</p> @enderror

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-6">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_transfer_1314349b') }}">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="draft">{{ __('application.draft_23d33e22') }}</option>
                <option value="completed">{{ __('application.completed_1798b3ba') }}</option>
                <option value="cancelled">{{ __('application.cancelled_a1bf92ef') }}</option>
            </select>
            <select wire:model.live="fromFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.from_any_72a37172') }}</option>
                @foreach (StockLocation::orderBy('name')->get() as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="toFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.to_any_508fe825') }}</option>
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

        <x-table :headers="[__('application.transfer_87c43f1d'), __('application.date_eb9a4bc1'), __('application.from_3f66052a'), __('application.to_ae79ea1e'), __('application.items_44d25b5d'), __('application.quantity_44f6af69'), __('application.created_by_43de2bcd'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($transfers as $transfer)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 font-black">{{ $transfer->transfer_number }}</td>
                    <td class="px-4 py-3">{{ $transfer->transfer_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $transfer->fromLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $transfer->toLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $transfer->items_count }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($transfer->items_sum_quantity) }}</td>
                    <td class="px-4 py-3">{{ $transfer->createdBy?->name }}</td>
                    <td class="px-4 py-3"><span class="{{ $transfer->status === 'completed' ? 'badge-success' : ($transfer->status === 'cancelled' ? 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::status($transfer->status) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('stock-transfers.show', $transfer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.view_69bd4ef9') }}</a>
                            @if ($transfer->canBeModified() && $this->canManage())
                                <a href="{{ route('stock-transfers.edit', $transfer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                                <button wire:click="completeTransfer({{ $transfer->id }})" class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">{{ __('application.complete_1f5a1abf') }}</button>
                            @endif
                            @if ($transfer->status === 'draft' && $this->canCancel())
                                <button wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="{{ __('application.cancel_this_transfer_dc0a31f0') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.cancel_77dfd213') }}</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_stock_transfers_found_2245bd2e') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $transfers->links() }}</div>
    </x-card>
</div>
