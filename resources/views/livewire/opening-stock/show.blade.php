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
    <x-page-header title="Opening Stock {{ $openingStock->reference_number }}" description="{{ __('application.posted_document_read_only_494792c8') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Opening Stock' => route('opening-stock.index'), $openingStock->reference_number => null]" />
    @if (session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-4 font-bold text-emerald-700">{{ session('success') }}</div>@endif
    <x-card>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-xs text-slate-500">{{ __('application.opening_date_f84ad2c7') }}</p><p class="font-black">{{ $openingStock->opening_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('application.branch_1627510b') }}</p><p class="font-black">{{ $openingStock->branch?->name }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('application.stock_location_50e3c4fe') }}</p><p class="font-black">{{ $openingStock->stockLocation?->name }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('application.posted_by_ac421e3f') }}</p><p class="font-black">{{ $openingStock->creator?->name }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('application.posted_at_82e3b058') }}</p><p class="font-black">{{ $openingStock->posted_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('application.total_opening_value_6adf305a') }}</p><p class="font-black">TZS {{ \App\Support\NumberFormatter::money($openingStock->total_value) }}</p></div>
        </div>
        @if ($openingStock->notes)<p class="mt-4 text-sm">{{ $openingStock->notes }}</p>@endif
    </x-card>
    <x-card title="{{ __('application.products_fe0a091f') }}" class="mt-5">
        <x-table :headers="[__('application.product_dd3b86d1'), __('terminology.entered_quantity_0b7837a6'), __('application.unit_f6b935ab'), __('terminology.base_quantity_736e0239'), __('application.unit_cost_01052520'), __('application.total_cost_b5d8da40'), __('terminology.batch_expiry_df4ebc16'), __('application.notes_70440046')]">
            @foreach ($openingStock->lines as $line)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $line->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->transaction_quantity) }}</td>
                    <td class="px-4 py-3">{{ $line->transaction_unit_code_snapshot ?: $line->transaction_unit_name_snapshot }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->base_quantity) }} {{ $line->product?->unit?->short_name }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($line->unit_cost) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($line->total_cost) }}</td>
                    <td class="px-4 py-3">{{ $line->batch_number ?: '-' }} @if ($line->expiry_date)/ {{ $line->expiry_date->locale(app()->getLocale())->translatedFormat('d M Y') }}@endif</td>
                    <td class="px-4 py-3">{{ $line->notes }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
