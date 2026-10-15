<?php

use App\Models\StockAdjustment;
use App\Services\InventoryService;

use function Livewire\Volt\layout;

layout('layouts.app');

$approve = function (int $adjustmentId, InventoryService $inventory) {
    $inventory->approveAdjustment(StockAdjustment::findOrFail($adjustmentId), auth()->id());
    session()->flash('success', __('application.stock_adjustment_approved_and_movement_created_8d12160a'));
};

$reject = function (int $adjustmentId) {
    StockAdjustment::whereKey($adjustmentId)->whereIn('status', ['pending', 'pending_approval'])->update([
        'status' => 'rejected',
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);
    session()->flash('success', __('application.stock_adjustment_rejected_7c777347'));
};

?>

<div>
    <x-page-header title="{{ __('application.approve_stock_adjustments_3c82768b') }}" description="{{ __('application.approve_pending_location_based_stock_adjustments_f8b24d6c') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => route('stock-adjustments.index'), 'Approve' => null]" />

    <x-card>
        @php $adjustments = StockAdjustment::with(['product', 'stockLocation', 'requester'])->withCount('lines')->whereIn('status', ['pending', 'pending_approval'])->latest()->get(); @endphp
        <x-table :headers="[__('application.reference_db1c7845'), __('application.date_eb9a4bc1'), __('terminology.product_s_0a28c3dc'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.qty_1e5ff9e5'), __('application.reason_f219cc06'), __('terminology.requested_by_343959a4'), __('application.actions_c3cd636a')]">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->reference_number ?: 'ADJ-'.$adjustment->id }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_date?->locale(app()->getLocale())->translatedFormat('d M Y') ?? $adjustment->created_at?->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->lines_count > 1 ? $adjustment->lines_count.' products' : $adjustment->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($adjustment->adjustment_type) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($adjustment->quantity) }}</td>
                    <td class="px-4 py-3">{{ $adjustment->reason }}</td>
                    <td class="px-4 py-3">{{ $adjustment->requester?->name }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2">
                            <button wire:click="approve({{ $adjustment->id }})" class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">{{ __('application.approve_7b2c7f14') }}</button>
                            <button wire:click="reject({{ $adjustment->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.reject_2b03b592') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_pending_adjustments_6e4386b5') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
