<?php

use App\Models\OpeningStock;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '']);
mount(fn () => abort_unless(InventorySettings::warehouseEnabled() && auth()->user()->can('opening_stock.view'), 403));
?>
<div>
    <x-page-header title="Opening Stock" description="Stock owned before HARDEX, posted without a purchase or supplier debt." :breadcrumbs="['Dashboard' => route('dashboard'), 'Opening Stock' => null]">
        @can('opening_stock.create')<a href="{{ route('opening-stock.create') }}" wire:navigate class="rounded-xl bg-cyan-600 px-4 py-2 font-black text-white">Create Opening Stock</a>@endcan
    </x-page-header>
    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input mb-4 max-w-sm" placeholder="Search reference number">
        @php
            $user = auth()->user();
            $companyScope = AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) === AuthorizationScope::COMPANY;
            $visibleLocations = $companyScope ? null : AuthorizationScope::stockLocationsForBranch($user, 'can_view', (int) $user->branch_id)->pluck('id');
            $rows = OpeningStock::query()->with(['branch', 'stockLocation', 'creator'])->where('company_id', $user->company_id)
                ->when(! $companyScope, fn ($query) => $query->where('branch_id', $user->branch_id)->whereIn('stock_location_id', $visibleLocations))
                ->when($search, fn ($query) => $query->where('reference_number', 'like', '%'.$search.'%'))
                ->latest()->paginate(15);
        @endphp
        <x-table :headers="['Reference', 'Opening Date', 'Branch', 'Location', 'Products', 'Base Quantity', 'Opening Value', 'Posted By']">
            @forelse ($rows as $opening)
                <tr>
                    <td class="px-4 py-3 font-bold"><a href="{{ route('opening-stock.show', $opening) }}" wire:navigate class="text-cyan-700">{{ $opening->reference_number }}</a></td>
                    <td class="px-4 py-3">{{ $opening->opening_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $opening->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $opening->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $opening->total_products }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($opening->total_base_quantity) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($opening->total_value) }}</td>
                    <td class="px-4 py-3">{{ $opening->creator?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No Opening Stock documents yet.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-card>
</div>
