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
    <x-page-header title="{{ __('application.stock_adjustments_6ed8f4af') }}" description="{{ __('application.use_this_after_a_physical_count_when_hardex_quantity_differs_from_physical__41f0a7a5') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => null]">
        <a href="{{ route('stock-adjustments.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.create_adjustment_f895fb92') }}</a>
        @if (\App\Support\InventorySettings::warehouseEnabled() && auth()->user()->can('opening_stock.create'))
            <a href="{{ route('opening-stock.create') }}" wire:navigate class="rounded-xl border border-cyan-200 px-4 py-2 text-sm font-bold text-cyan-700">{{ __('application.entering_stock_you_already_had_before_hardex_create_opening_stock_7f3f36ae') }}</a>
        @endif
        @if (auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']))
            <a href="{{ route('stock-adjustments.approve') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.approve_7b2c7f14') }}</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="draft">{{ __('application.draft_23d33e22') }}</option>
                <option value="pending">{{ __('application.pending_96f608c1') }}</option>
                <option value="pending_approval">{{ __('application.pending_approval_7ba0a4f0') }}</option>
                <option value="approved">{{ __('application.approved_41b81eb8') }}</option>
                <option value="posted">{{ __('application.posted_301777ff') }}</option>
                <option value="rejected">{{ __('application.rejected_27eeb7a2') }}</option>
                <option value="cancelled">{{ __('application.cancelled_a1bf92ef') }}</option>
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

        <x-table :headers="[__('application.reference_db1c7845'), __('application.date_eb9a4bc1'), __('terminology.product_s_0a28c3dc'), __('application.location_d219c681'), __('terminology.direction_fd8e45ba'), __('application.qty_1e5ff9e5'), __('application.reason_f219cc06'), __('application.status_bae7d5be'), __('application.created_by_43de2bcd'), __('application.approved_by_2b62a43b')]">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->reference_number ?: 'ADJ-'.$adjustment->id }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_date?->locale(app()->getLocale())->translatedFormat('d M Y') ?? $adjustment->created_at?->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->lines_count > 1 ? $adjustment->lines_count.' products' : $adjustment->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($adjustment->adjustment_type) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($adjustment->quantity) }}</td>
                    <td class="px-4 py-3">{{ $adjustment->reason }}</td>
                    <td class="px-4 py-3"><span class="{{ in_array($adjustment->status, ['approved', 'posted'], true) ? 'badge-success' : ($adjustment->status === 'rejected' ? 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::label($adjustment->status) }}</span></td>
                    <td class="px-4 py-3">{{ $adjustment->requester?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->approver?->name ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_stock_adjustments_found_74fde3fc') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $adjustments->links() }}</div>
    </x-card>
</div>
