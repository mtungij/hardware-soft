<?php

use App\Models\InternalSale;
use App\Models\StockMovement;
use App\Services\InternalSaleService;
use App\Support\AuthorizationScope;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['saleId' => null]);

mount(function (InternalSale $internalSale) {
    abort_unless((int) $internalSale->company_id === (int) auth()->user()->company_id
        && (AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $internalSale->from_location_id)
            || AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $internalSale->to_location_id)), 403);
    $this->saleId = $internalSale->id;
});

$complete = function (InternalSaleService $service): void {
    $sale = InternalSale::findOrFail($this->saleId);
    $service->complete($sale, auth()->user());
    session()->flash('success', __('application.internal_sale_completed_b0bd87f6'));
};

$cancel = function (InternalSaleService $service): void {
    $sale = InternalSale::findOrFail($this->saleId);
    $service->cancelDraft($sale, auth()->user());
    session()->flash('success', __('application.internal_sale_cancelled_4813552b'));
};

?>

<div>
    @php
        $sale = InternalSale::with(['items.product', 'fromLocation', 'toLocation', 'branch', 'creator'])->findOrFail($saleId);
        $canViewCost = auth()->user()->can('internal_sales.view_cost');
        $canViewMargin = auth()->user()->can('internal_sales.view_margin') && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->from_location_id);
        $canManageBoth = AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->from_location_id) && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->to_location_id);
    @endphp
    <x-page-header title="Internal Sale {{ $sale->internal_sale_number }}" description="{{ __('application.internal_value_is_informational_and_is_excluded_from_company_revenue_0ad58dce') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Internal Sales' => route('internal-sales.index'), $sale->internal_sale_number => null]">
        @if ($sale->status === 'draft' && $canManageBoth && auth()->user()->can('internal_sales.create'))
            <a href="{{ route('internal-sales.edit', $sale) }}" wire:navigate class="rounded-xl border px-4 py-2">{{ __('application.edit_draft_5964634b') }}</a>
        @endif
        @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_delivery_note'))
            <a href="{{ route('internal-sales.delivery-note.print', $sale) }}" target="_blank" rel="noopener" class="rounded-xl border px-4 py-2">{{ __('application.print_delivery_note_69fa3566') }}</a>
            <a href="{{ route('internal-sales.delivery-note.pdf', $sale) }}" class="rounded-xl border px-4 py-2">{{ __('application.delivery_pdf_24e3f272') }}</a>
        @endif
        @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_value_note'))
            <a href="{{ route('internal-sales.value-note.print', $sale) }}" target="_blank" rel="noopener" class="rounded-xl border px-4 py-2">{{ __('application.print_internal_sale_value_note_dcf3d3b7') }}</a>
        @endif
        <a href="{{ route('internal-sales.index') }}" wire:navigate class="rounded-xl border px-4 py-2">{{ __('application.back_b52b36b7') }}</a>
    </x-page-header>
    <x-card>
        <div class="grid gap-3 md:grid-cols-3 text-sm">
            <p><b>{{ __('application.status_11dc9e19') }}</b> {{ \App\Support\UiText::status($sale->status) }}</p><p><b>{{ __('application.date_81b7d2ea') }}</b> {{ $sale->sale_date?->format('Y-m-d') }}</p><p><b>{{ __('application.branch_8b31d33d') }}</b> {{ $sale->branch?->name }}</p>
            <p><b>{{ __('application.source_922acd28') }}</b> {{ $sale->fromLocation?->name }}</p><p><b>{{ __('application.destination_b61bb042') }}</b> {{ $sale->toLocation?->name }}</p><p><b>{{ __('application.created_by_99d17454') }}</b> {{ $sale->creator?->name }}</p>
        </div>
        @if ($sale->notes)<p class="mt-3 text-sm">{{ $sale->notes }}</p>@endif
        @if ($sale->status === 'draft' && $canManageBoth)
            <div class="mt-4 flex gap-3">
                @can('internal_sales.complete')<button wire:click="complete" wire:confirm="{{ __('application.complete_and_post_stock_movements_292ee055') }}" class="rounded-lg bg-build-orange px-4 py-2 font-bold text-white">{{ __('application.complete_1f5a1abf') }}</button>@endcan
                @can('internal_sales.cancel')<button wire:click="cancel" wire:confirm="{{ __('application.cancel_this_draft_3a17f177') }}" class="rounded-lg border px-4 py-2 font-bold">{{ __('application.cancel_draft_353b843d') }}</button>@endcan
            </div>
        @endif
    </x-card>
    <x-card class="mt-4"><h3 class="mb-3 font-bold">{{ __('application.items_44d25b5d') }}</h3>
        <x-table :headers="array_merge([__('application.product_dd3b86d1'), __('terminology.transaction_qty_8c69a9bb'), __('terminology.base_qty_1b8739d8'), __('application.internal_unit_price_98164503'), __('application.price_source_67fcdada'), __('terminology.internal_value_ed3a29e0')], $canViewCost ? [__('terminology.company_unit_cost_ae6b5f8e'), __('terminology.source_acquisition_cost_80cb9ff9'), __('terminology.destination_acquisition_cost_b5eca34e')] : [])">
            @foreach ($sale->items as $item)
                <tr>
                    <td class="px-4 py-3">{{ $item->product?->displayName() }}</td>
                    <td class="px-4 py-3 text-right">{{ $item->transaction_quantity }} {{ $item->transaction_unit_code_snapshot }}</td>
                    <td class="px-4 py-3 text-right">{{ $item->base_quantity }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->internal_unit_price) }}</td>
                    <td class="px-4 py-3 text-xs">{{ $item->price_source ?: '—' }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                    @if($canViewCost)
                        <td class="px-4 py-3 text-right">{{ $item->company_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->company_base_unit_cost) }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->source_acquisition_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->source_acquisition_base_unit_cost) }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->destination_acquisition_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->destination_acquisition_base_unit_cost) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-table>
        <p class="mt-4 text-right font-bold">{{ __('application.internal_value_tzs_d7db34ec') }} {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</p>
    </x-card>
    @if ($sale->status === 'completed' && $canViewMargin)
        @php
            $sourceCost = $sale->items->sum(fn ($item) => (float) $item->base_quantity * (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost));
            $internalMargin = (float) $sale->total_internal_value - $sourceCost;
        @endphp
        <x-card class="mt-4"><h3 class="mb-3 font-bold">{{ __('application.source_location_performance_internal_location_margin_9f1db542') }}</h3>
            <x-table :headers="[__('application.product_dd3b86d1'), __('terminology.internal_value_ed3a29e0'), __('terminology.source_cost_919d8d46'), __('terminology.internal_gross_profit_89e1ad3d')]">
                @foreach($sale->items as $item)
                    @php $lineCost = (float) $item->base_quantity * (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost); @endphp
                    <tr><td class="px-4 py-3">{{ $item->product?->displayName() }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($lineCost) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money((float) $item->line_total - $lineCost) }}</td></tr>
                @endforeach
            </x-table>
            <p class="mt-3 text-right font-bold">{{ __('application.internal_location_margin_tzs_62150c31') }} {{ \App\Support\NumberFormatter::money($internalMargin) }}</p>
            <p class="text-xs text-slate-500">{{ __('application.analytical_location_margin_excluded_from_consolidated_company_profit_c9e82b43') }}</p>
        </x-card>
    @endif
    @if ($sale->status === 'completed')
        @php $movements = StockMovement::where('reference_type', InternalSale::class)->where('reference_id', $sale->id)->whereIn('stock_location_id', AuthorizationScope::stockLocationIds(auth()->user()))->orderBy('id')->get(); @endphp
        <x-card class="mt-4"><h3 class="mb-3 font-bold">{{ __('application.stock_posting_12829001') }}</h3>
            <x-table :headers="array_merge([__('application.movement_dc812752'), __('application.location_d219c681'), __('terminology.base_quantity_671469bb')], $canViewCost ? [__('terminology.company_unit_cost_ae6b5f8e'), __('terminology.location_acquisition_cost_645c24a1')] : [])">
                @foreach ($movements as $movement)
                    <tr><td class="px-4 py-3">{{ \App\Support\UiText::label($movement->movement_type) }}</td><td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td><td class="px-4 py-3 text-right">{{ $movement->signedQuantity() }}</td>@if($canViewCost)<td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($movement->unit_cost) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($movement->location_acquisition_unit_cost) }}</td>@endif</tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
</div>
