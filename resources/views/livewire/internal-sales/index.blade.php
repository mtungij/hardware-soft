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
    <x-page-header title="Internal Sales" description="Internal stock sales between authorized locations." :breadcrumbs="['Dashboard' => route('dashboard'), 'Internal Sales' => null]">
        @can('internal_sales.create')
            <a href="{{ route('internal-sales.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-bold text-white">Create Internal Sale</a>
        @endcan
    </x-page-header>
    <x-card>
        @php $visibleLocations = \App\Models\StockLocation::query()->whereIn('id', AuthorizationScope::stockLocationIds(auth()->user()))->orderBy('name')->get(); @endphp
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" placeholder="Internal Sale number" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <select wire:model.live="status" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All statuses</option><option value="draft">Draft</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
            <input wire:model.live="dateFrom" type="date" aria-label="Date from" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <input wire:model.live="dateTo" type="date" aria-label="Date to" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
            <select wire:model.live="fromId" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All source locations</option>@foreach($visibleLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
            <select wire:model.live="toId" class="rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All destination locations</option>@foreach($visibleLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
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
        <x-table :headers="['Number', 'Date', 'Branch', 'Source', 'Destination', 'Internal value', 'Status', 'Actions']">
            @forelse ($sales as $sale)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $sale->internal_sale_number }}</td>
                    <td class="px-4 py-3">{{ $sale->sale_date?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3">{{ $sale->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $sale->fromLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $sale->toLocation?->name }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</td>
                    <td class="px-4 py-3">{{ ucfirst($sale->status) }}</td>
                    <td class="px-4 py-3"><a href="{{ route('internal-sales.show', $sale) }}" wire:navigate class="font-bold text-build-orange">View</a> @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_delivery_note'))<a href="{{ route('internal-sales.delivery-note.print', $sale) }}" target="_blank" rel="noopener" class="ml-3 text-sm font-bold">Delivery Note</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No internal sales found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $sales->links() }}</div>
    </x-card>
</div>
