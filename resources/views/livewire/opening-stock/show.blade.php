<?php

use App\Models\OpeningStock;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\Auth;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['openingStock' => null]);
mount(function (OpeningStock $openingStock): void {
    $user = Auth::user();
    abort_unless(InventorySettings::warehouseEnabled() && $user->can('opening_stock.view')
        && (int) $openingStock->company_id === (int) $user->company_id, 403);
    $companyScope = AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) === AuthorizationScope::COMPANY;
    abort_unless($companyScope || ((int) $openingStock->branch_id === (int) $user->branch_id
        && AuthorizationScope::stockLocationsForBranch($user, 'can_view', (int) $user->branch_id)->contains('id', $openingStock->stock_location_id)), 403);
    $this->openingStock = $openingStock->load(['branch', 'stockLocation', 'creator', 'lines.product', 'lines.transactionUnit']);
});
?>
<div>
    <x-page-header title="Opening Stock {{ $openingStock->reference_number }}" description="Posted document · read only" :breadcrumbs="['Dashboard' => route('dashboard'), 'Opening Stock' => route('opening-stock.index'), $openingStock->reference_number => null]" />
    @if (session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-4 font-bold text-emerald-700">{{ session('success') }}</div>@endif
    <x-card>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-xs text-slate-500">Opening Date</p><p class="font-black">{{ $openingStock->opening_date->format('d M Y') }}</p></div>
            <div><p class="text-xs text-slate-500">Branch</p><p class="font-black">{{ $openingStock->branch?->name }}</p></div>
            <div><p class="text-xs text-slate-500">Stock Location</p><p class="font-black">{{ $openingStock->stockLocation?->name }}</p></div>
            <div><p class="text-xs text-slate-500">Posted By</p><p class="font-black">{{ $openingStock->creator?->name }}</p></div>
            <div><p class="text-xs text-slate-500">Posted At</p><p class="font-black">{{ $openingStock->posted_at?->format('d M Y H:i') }}</p></div>
            <div><p class="text-xs text-slate-500">Total Opening Value</p><p class="font-black">TZS {{ \App\Support\NumberFormatter::money($openingStock->total_value) }}</p></div>
        </div>
        @if ($openingStock->notes)<p class="mt-4 text-sm">{{ $openingStock->notes }}</p>@endif
    </x-card>
    <x-card title="Products" class="mt-5">
        <x-table :headers="['Product', 'Entered Quantity', 'Unit', 'Base Quantity', 'Unit Cost', 'Total Cost', 'Batch / Expiry', 'Notes']">
            @foreach ($openingStock->lines as $line)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $line->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->transaction_quantity) }}</td>
                    <td class="px-4 py-3">{{ $line->transaction_unit_code_snapshot ?: $line->transaction_unit_name_snapshot }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->base_quantity) }} {{ $line->product?->unit?->short_name }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($line->unit_cost) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($line->total_cost) }}</td>
                    <td class="px-4 py-3">{{ $line->batch_number ?: '-' }} @if ($line->expiry_date)/ {{ $line->expiry_date->format('d M Y') }}@endif</td>
                    <td class="px-4 py-3">{{ $line->notes }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
