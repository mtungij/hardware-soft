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
    <x-page-header title="{{ __('application.opening_stock_79ec9b63') }}" description="{{ __('application.stock_owned_before_hardex_posted_without_a_purchase_or_supplier_debt_b3791cf0') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Opening Stock' => null]">
        @can('opening_stock.create')<a href="{{ route('opening-stock.create') }}" wire:navigate class="rounded-xl bg-cyan-600 px-4 py-2 font-black text-white">{{ __('application.create_opening_stock_d8fd5c49') }}</a>@endcan
    </x-page-header>
    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input mb-4 max-w-sm" placeholder="{{ __('application.search_reference_number_9b3ff218') }}">
        @php
            $user = auth()->user();
            $companyScope = AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) === AuthorizationScope::COMPANY;
            $visibleLocations = $companyScope ? null : AuthorizationScope::stockLocationsForBranch($user, 'can_view', (int) $user->branch_id)->pluck('id');
            $rows = OpeningStock::query()->with(['branch', 'stockLocation', 'creator'])->where('company_id', $user->company_id)
                ->when(! $companyScope, fn ($query) => $query->where('branch_id', $user->branch_id)->whereIn('stock_location_id', $visibleLocations))
                ->when($search, fn ($query) => $query->where('reference_number', 'like', '%'.$search.'%'))
                ->latest()->paginate(15);
        @endphp
        <x-table :headers="[__('application.reference_db1c7845'), __('application.opening_date_f84ad2c7'), __('application.branch_1627510b'), __('application.location_d219c681'), __('application.products_fe0a091f'), __('terminology.base_quantity_736e0239'), __('terminology.opening_value_b9c7de8c'), __('application.posted_by_ac421e3f')]">
            @forelse ($rows as $opening)
                <tr>
                    <td class="px-4 py-3 font-bold"><a href="{{ route('opening-stock.show', $opening) }}" wire:navigate class="text-cyan-700">{{ $opening->reference_number }}</a></td>
                    <td class="px-4 py-3">{{ $opening->opening_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $opening->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $opening->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $opening->total_products }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($opening->total_base_quantity) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($opening->total_value) }}</td>
                    <td class="px-4 py-3">{{ $opening->creator?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_opening_stock_documents_yet_234589d2') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-card>
</div>
