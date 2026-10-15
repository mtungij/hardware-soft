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
        $this->addError('transfer', __('terminology.only_completed_uncancelled_transfers_can_be_cancelled_c69f57de'));
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

    $this->dispatch('close-modal', 'cancel-stock-transfer');

    session()->flash('success', __('terminology.stock_transfer_cancelled_stock_movements_have_been_reversed_90f0fb00'));

    return $this->redirectRoute('stock-transfers.index', navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.stock_transfer_details_0f9f311a') }}" description="{{ __('application.transfer_header_items_and_stock_movement_references_66eb5864') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), $stockTransfer->transfer_number => null]">
        @if ($stockTransfer->status === 'completed' && $stockTransfer->cancelled_at === null && $stockTransfer->canCancel(auth()->user()))
            <button type="button" wire:click="openCancellation" wire:loading.attr="disabled" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">{{ __(__('terminology.cancel_transfer_f8e5b50d')) }}</button>
        @endif
        <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.back_b52b36b7') }}</a>
    </x-page-header>

    @if ($stockTransfer->status === 'completed')
        <x-card title="{{ __('application.documents_687c8286') }}" class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div><h2 class="font-black">{{ __('application.stock_transfer_note_19b46fc7') }} {{ $stockTransfer->transfer_number }}</h2>
                    <p>{{ $stockTransfer->fromLocation?->name }} → {{ $stockTransfer->toLocation?->name }}</p>
                    <span class="badge-success">{{ __('application.completed_1798b3ba') }}</span>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('stock-transfers.note.print', $stockTransfer) }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2 font-bold">{{ __('application.print_transfer_note_d55a844e') }}</a>
                    <a href="{{ route('stock-transfers.note.pdf', $stockTransfer) }}" class="rounded-xl bg-build-orange px-4 py-2 font-bold text-white">{{ __('application.download_pdf_98e5ef06') }}</a>
                </div>
            </div>
        </x-card>
    @endif

    @error('transfer') <p role="alert" class="mb-4 text-red-600">{{ $message }}</p> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="{{ __('application.transfer_summary_ab68939e') }}">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.transfer_87c43f1d') }}</dt><dd class="font-black">{{ $stockTransfer->transfer_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.date_eb9a4bc1') }}</dt><dd>{{ $stockTransfer->transfer_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.from_3f66052a') }}</dt><dd>{{ $stockTransfer->fromLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.to_ae79ea1e') }}</dt><dd>{{ $stockTransfer->toLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.created_by_43de2bcd') }}</dt><dd>{{ $stockTransfer->createdBy?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.completed_by_c30c0263') }}</dt><dd>{{ $stockTransfer->completedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.completed_date_0089d3b1') }}</dt><dd>{{ $stockTransfer->completed_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.status_bae7d5be') }}</dt><dd><span class="{{ $stockTransfer->status === 'completed' ? 'badge-success' : 'badge-warning' }}">{{ __(\App\Support\UiText::status($stockTransfer->status)) }}</span></dd></div>
                @if ($stockTransfer->status === 'cancelled')
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __(__('terminology.cancelled_by_adfc64da')) }}</dt><dd>{{ $stockTransfer->cancelledBy?->name ?? '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __(__('terminology.cancelled_date_1a1d87a9')) }}</dt><dd>{{ $stockTransfer->cancelled_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? '-' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('application.reason_f219cc06') }}</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $stockTransfer->cancellation_reason ?? '-' }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="{{ __('application.transfer_items_4846eb76') }}" class="xl:col-span-2">
            <x-table :headers="[__('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('application.quantity_44f6af69'), __('application.notes_70440046')]">
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

    <x-card title="{{ __('application.stock_movement_references_a1942ab8') }}" class="mt-6">
        @php
            $movements = StockMovement::with(['product', 'stockLocation'])
                ->where('company_id', $stockTransfer->company_id)
                ->where('reference_type', StockTransfer::class)
                ->where('reference_id', $stockTransfer->id)
                ->orderBy('id')
                ->get();
        @endphp
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.product_dd3b86d1'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.quantity_44f6af69'), __('terminology.original_movement_8db9cfe4'), __('application.reason_f219cc06')]">
            @forelse ($movements as $movement)
                <tr>
                    <td class="px-4 py-3">{{ $movement->movement_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $movement->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ __(['transfer_out' => 'Transfer Out', 'transfer_in' => 'Transfer In', 'transfer_cancel_out' => 'Cancellation Out', 'transfer_cancel_in' => 'Cancellation In'][$movement->movement_type] ?? $movement->movement_type) }}
                        <span class="block font-mono text-xs text-slate-500">{{ \App\Support\UiText::label($movement->movement_type) }}</span></td>
                    <td class="px-4 py-3">{{ $movement->signedQuantity() > 0 ? '+' : '' }}{{ \App\Support\NumberFormatter::quantity($movement->signedQuantity()) }}</td>
                    <td class="px-4 py-3">{{ $movement->original_movement_id ? '#'.$movement->original_movement_id : '-' }}</td>
                    <td class="px-4 py-3">{{ $movement->notes ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_stock_movements_yet_draft_transfers_do_not_affect_stock_78cd434e') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>
    <x-modal name="cancel-stock-transfer" maxWidth="lg" focusable>
        {{-- Let Livewire render validation errors inside the modal instead of native validation blocking submission. --}}
        <form wire:submit="cancelTransfer" novalidate class="space-y-4 overflow-y-auto p-6">
            <h2 class="text-lg font-black">{{ __(__('terminology.cancel_transfer_f8e5b50d')) }}</h2>
            <p>{{ __(__('terminology.cancelling_reverses_this_transfer_stock_is_removed_from_the_destination_and_487837b9')) }}</p>
            <p class="font-bold">{{ $stockTransfer->toLocation?->name }} → {{ $stockTransfer->fromLocation?->name }}</p>
            <label for="cancellation-reason" class="block font-bold">{{ __(__('terminology.cancellation_reason_2920b720')) }}</label>
            <textarea id="cancellation-reason" wire:model="cancellationReason" required maxlength="2000" rows="3" class="w-full rounded-lg border border-slate-300 dark:bg-slate-800"></textarea>
            @error('cancellationReason') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @error('cancellation_reason') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="confirmCancellation" required>
                <span>{{ __(__('terminology.i_confirm_that_i_want_to_cancel_this_transfer_and_reverse_its_stock_movemen_5113e194')) }}</span>
            </label>
            @error('confirmCancellation') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @error('transfer') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close-modal', 'cancel-stock-transfer')" wire:loading.attr="disabled" wire:target="cancelTransfer" class="rounded-lg border px-4 py-2">{{ __('application.back_b52b36b7') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="cancelTransfer" class="rounded-lg bg-red-600 px-4 py-2 font-bold text-white">
                    <span wire:loading.remove wire:target="cancelTransfer">{{ __(__('terminology.confirm_cancellation_cb645f23')) }}</span>
                    <span wire:loading wire:target="cancelTransfer">{{ __(__('terminology.cancelling_f498a5f0')) }}</span>
                </button>
            </div>
        </form>
    </x-modal>
</div>
