<?php

use App\Models\InternalSale;
use App\Support\AuthorizationScope;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '', 'status' => '', 'dateFrom' => '', 'dateTo' => '', 'fromId' => '', 'toId' => '']);

?>

<div>
    <x-page-header title="{{ __('application.internal_sales_a7596208') }}" description="{{ __('application.internal_stock_sales_between_authorized_locations_5d02ed68') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Internal Sales' => null]">
        @can('internal_sales.create')
            <a href="{{ route('internal-sales.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-bold text-white">{{ __('application.create_internal_sale_601715df') }}</a>
        @endcan
    </x-page-header>
    <x-card>
        @php $visibleLocations = \App\Models\StockLocation::query()->whereIn('id', AuthorizationScope::stockLocationIds(auth()->user()))->orderBy('name')->get(); @endphp
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" placeholder="{{ __('application.internal_sale_number_64adeb2c') }}" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <select wire:model.live="status" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_statuses_6405179d') }}</option><option value="draft">{{ __('application.draft_23d33e22') }}</option><option value="completed">{{ __('application.completed_1798b3ba') }}</option><option value="cancelled">{{ __('application.cancelled_a1bf92ef') }}</option></select>
            <input wire:model.live="dateFrom" type="date" aria-label="{{ __('application.date_from_4a6be5e9') }}" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <input wire:model.live="dateTo" type="date" aria-label="{{ __('application.date_to_cad6bd30') }}" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <select wire:model.live="fromId" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_source_locations_cd376e05') }}</option>@foreach($visibleLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
            <select wire:model.live="toId" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_destination_locations_9953953c') }}</option>@foreach($visibleLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
        </div>
        @php
            $user = auth()->user();
            $visible = AuthorizationScope::stockLocationIds($user);
            $sales = InternalSale::query()->where('company_id', $user->company_id)
                ->where(fn ($query) => $query->whereIn('from_location_id', $visible)->orWhereIn('to_location_id', $visible))
                ->with(['fromLocation', 'toLocation', 'branch'])
                ->when($search, fn ($query) => $query->where('internal_sale_number', 'like', '%'.$search.'%'))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($dateFrom, fn ($query) => $query->whereDate('sale_date', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('sale_date', '<=', $dateTo))
                ->when($fromId, fn ($query) => $query->where('from_location_id', $fromId))
                ->when($toId, fn ($query) => $query->where('to_location_id', $toId))
                ->latest()->paginate(15);
        @endphp
        <x-table :headers="[__('application.number_b7baa1d4'), __('application.date_eb9a4bc1'), __('application.branch_1627510b'), __('application.source_6da13add'), __('application.destination_d4271349'), __('terminology.internal_value_ed3a29e0'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($sales as $sale)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $sale->internal_sale_number }}</td>
                    <td class="px-4 py-3">{{ $sale->sale_date?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3">{{ $sale->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $sale->fromLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $sale->toLocation?->name }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::status($sale->status) }}</td>
                    <td class="px-4 py-3"><a href="{{ route('internal-sales.show', $sale) }}" wire:navigate class="font-bold text-build-orange">{{ __('application.view_69bd4ef9') }}</a> @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_delivery_note'))<a href="{{ route('internal-sales.delivery-note.print', $sale) }}" target="_blank" rel="noopener" class="ml-3 text-sm font-bold">{{ __('application.delivery_note_da6ce2cc') }}</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_internal_sales_found_0959556c') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $sales->links() }}</div>
    </x-card>
</div>
