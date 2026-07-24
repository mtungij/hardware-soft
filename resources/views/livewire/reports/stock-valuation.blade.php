<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'search' => '']);

mount(function () {
    $this->branch_id = request('branch_id', $this->branch_id);
    $this->search = request('search', $this->search);
});

?>

<div>
    <x-page-header title="Stock Valuation Report" description="Ledger-based quantity and valuation by branch, location, product, and category." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Stock Valuation' => null]"><x-export-actions export="reports.stock-valuation" :params="compact('branch_id', 'search')" /></x-page-header>
    @php
        $rows = collect(app(FinancialReportService::class)->stockValuation($branch_id ? (int) $branch_id : null))
            ->filter(fn ($row) => blank($search) || str_contains(strtolower($row['product'].' '.$row['size'].' '.$row['category'].' '.$row['location']), strtolower($search)));
        $hasSizes = $rows->contains(fn ($row) => filled($row['size']));
        $headers = ['Branch', 'Location', 'Product', 'Measurement Type'];
        if ($hasSizes) {
            $headers[] = 'Size';
        }
        array_push($headers, 'Category', 'Unit', 'Stock', 'Avg Cost', 'Value');
    @endphp
    <x-card><div class="grid gap-3 md:grid-cols-2"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search product/category/location"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Items</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Quantity</p><p class="text-2xl font-black">{{ \App\Support\NumberFormatter::quantity($rows->sum('quantity')) }}</p></x-card><x-card><p class="text-sm text-slate-500">Stock Value</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('value')) }}</p></x-card></div>
    <x-card class="mt-4">
        <x-table :headers="$headers">
            @foreach ($rows as $row)
                <tr>
                    <td class="px-4 py-3">{{ $row['branch'] }}</td>
                    <td class="px-4 py-3">{{ $row['location'] }}</td>
                    <td class="px-4 py-3 font-bold">{{ $row['product'] }}</td>
                    <td class="px-4 py-3">{{ $row['measurement_type'] }}</td>
                    @if ($hasSizes)
                        <td class="px-4 py-3">{{ $row['size'] ?: '—' }}</td>
                    @endif
                    <td class="px-4 py-3">{{ $row['category'] }}</td>
                    <td class="px-4 py-3">{{ $row['unit'] }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($row['quantity']) }} {{ $row['unit'] }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($row['average_cost']) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($row['value']) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
