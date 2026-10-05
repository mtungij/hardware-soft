<?php

use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['stockTransfer' => null])->locked();
state(['cancellationReason' => '', 'confirmCancellation' => false]);

mount(function (StockTransfer $stockTransfer) {
    app(\App\Services\StockTransferNoteService::class)->authorize($stockTransfer, auth()->user());
    $this->stockTransfer = $stockTransfer->load(['branch', 'fromLocation', 'toLocation', 'createdBy', 'completedBy', 'cancelledBy', 'items.product.unit']);
});

$openCancellation = function () {
    $transfer = StockTransfer::findOrFail($this->stockTransfer->id);
    abort_unless($transfer->canCancel(auth()->user()), 403);
    if ($transfer->status !== 'completed' || $transfer->cancelled_at !== null) {
        $this->addError('transfer', __('Only completed, uncancelled transfers can be cancelled.'));
        return;
    }
    $this->resetValidation();
    $this->cancellationReason = '';
    $this->confirmCancellation = false;
    $this->dispatch('open-modal', 'cancel-stock-transfer');
};

$cancelTransfer = function (\App\Services\InventoryService $inventory) {
    abort_unless($this->stockTransfer->canCancel(auth()->user()), 403);
    $this->cancellationReason = trim($this->cancellationReason);
    $this->validate([
        'cancellationReason' => ['required', 'string', 'max:2000'],
        'confirmCancellation' => ['accepted'],
    ]);
    $inventory->cancelStockTransfer($this->stockTransfer->id, auth()->id(), $this->cancellationReason);
    $this->stockTransfer = $this->stockTransfer->fresh(['branch', 'fromLocation', 'toLocation', 'createdBy', 'completedBy', 'cancelledBy', 'items.product.unit']);
    $this->dispatch('close-modal', 'cancel-stock-transfer');
    session()->flash('success', __('Stock transfer cancelled. Stock movements have been reversed.'));
};

?>

<div>
    <x-page-header title="Stock Transfer Details" description="Transfer header, items, and stock movement references." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), $stockTransfer->transfer_number => null]">
        @if ($stockTransfer->status === 'completed' && $stockTransfer->cancelled_at === null && $stockTransfer->canCancel(auth()->user()))
            <button type="button" wire:click="openCancellation" wire:loading.attr="disabled" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">{{ __('Cancel Transfer') }}</button>
        @endif
        <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Back</a>
    </x-page-header>

    @if ($stockTransfer->status === 'completed')
        <x-card title="Documents" class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div><h2 class="font-black">Stock Transfer Note · {{ $stockTransfer->transfer_number }}</h2>
                    <p>{{ $stockTransfer->fromLocation?->name }} → {{ $stockTransfer->toLocation?->name }}</p>
                    <span class="badge-success">Completed</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('stock-transfers.note.print', $stockTransfer) }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2 font-bold">Print Transfer Note</a>
                    <a href="{{ route('stock-transfers.note.pdf', $stockTransfer) }}" class="rounded-xl bg-build-orange px-4 py-2 font-bold text-white">Download PDF</a>
                </div>
            </div>
        </x-card>
    @endif

    @error('transfer') <p role="alert" class="mb-4 text-red-600">{{ $message }}</p> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Transfer Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Transfer #</dt><dd class="font-black">{{ $stockTransfer->transfer_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Date</dt><dd>{{ $stockTransfer->transfer_date->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">From</dt><dd>{{ $stockTransfer->fromLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">To</dt><dd>{{ $stockTransfer->toLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Created By</dt><dd>{{ $stockTransfer->createdBy?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Completed By</dt><dd>{{ $stockTransfer->completedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Completed Date</dt><dd>{{ $stockTransfer->completed_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><span class="{{ $stockTransfer->status === 'completed' ? 'badge-success' : 'badge-warning' }}">{{ __(ucfirst($stockTransfer->status)) }}</span></dd></div>
                @if ($stockTransfer->status === 'cancelled')
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('Cancelled By') }}</dt><dd>{{ $stockTransfer->cancelledBy?->name ?? '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('Cancelled Date') }}</dt><dd>{{ $stockTransfer->cancelled_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Reason') }}</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $stockTransfer->cancellation_reason ?? '-' }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="Transfer Items" class="xl:col-span-2">
            <x-table :headers="['Product', 'SKU', 'Unit', 'Quantity', 'Notes']">
                @foreach ($stockTransfer->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">{{ $item->product?->displayNameWithSize() }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $item->product?->sku }}</td>
                        <td class="px-4 py-3">{{ $item->product?->unit?->short_name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->quantity) }}</td>
                        <td class="px-4 py-3">{{ $item->notes ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="Stock Movement References" class="mt-6">
        @php
            $movements = StockMovement::with(['product', 'stockLocation'])
                ->where('company_id', $stockTransfer->company_id)
                ->where('reference_type', StockTransfer::class)
                ->where('reference_id', $stockTransfer->id)
                ->orderBy('id')
                ->get();
        @endphp
        <x-table :headers="['Date', 'Product', 'Location', 'Type', 'Quantity', 'Original Movement', 'Reason']">
            @forelse ($movements as $movement)
                <tr>
                    <td class="px-4 py-3">{{ $movement->movement_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $movement->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ __(['transfer_out' => 'Transfer Out', 'transfer_in' => 'Transfer In', 'transfer_cancel_out' => 'Cancellation Out', 'transfer_cancel_in' => 'Cancellation In'][$movement->movement_type] ?? $movement->movement_type) }}
                        <span class="block font-mono text-xs text-slate-500">{{ $movement->movement_type }}</span></td>
                    <td class="px-4 py-3">{{ $movement->signedQuantity() > 0 ? '+' : '' }}{{ \App\Support\NumberFormatter::quantity($movement->signedQuantity()) }}</td>
                    <td class="px-4 py-3">{{ $movement->original_movement_id ? '#'.$movement->original_movement_id : '-' }}</td>
                    <td class="px-4 py-3">{{ $movement->notes ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No stock movements yet. Draft transfers do not affect stock.</td></tr>
            @endforelse
        </x-table>
    </x-card>
    <x-modal name="cancel-stock-transfer" maxWidth="lg" focusable>
        <form wire:submit="cancelTransfer" class="space-y-4 overflow-y-auto p-6">
            <h2 class="text-lg font-black">{{ __('Cancel Transfer') }}</h2>
            <p>{{ __('Cancelling reverses this transfer: stock is removed from the destination and returned to the source. Original stock movements remain in the audit history.') }}</p>
            <p class="font-bold">{{ $stockTransfer->toLocation?->name }} → {{ $stockTransfer->fromLocation?->name }}</p>
            <label for="cancellation-reason" class="block font-bold">{{ __('Cancellation Reason') }}</label>
            <textarea id="cancellation-reason" wire:model="cancellationReason" required maxlength="2000" rows="3" class="w-full rounded-lg border border-slate-300 dark:bg-slate-800"></textarea>
            @error('cancellationReason') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @error('cancellation_reason') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="confirmCancellation" required>
                <span>{{ __('I confirm that I want to cancel this transfer and reverse its stock movements.') }}</span>
            </label>
            @error('confirmCancellation') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @error('transfer') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close-modal', 'cancel-stock-transfer')" class="rounded-lg border px-4 py-2">{{ __('Back') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-red-600 px-4 py-2 font-bold text-white">{{ __('Confirm Cancellation') }}</button>
            </div>
        </form>
    </x-modal>
</div>
