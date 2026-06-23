<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'search' => '']);

?>

<div>
    <x-page-header title="Stock Valuation Report" description="Ledger-based quantity and valuation by branch, location, product, and category." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Stock Valuation' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $rows = collect(app(FinancialReportService::class)->stockValuation($branch_id ? (int) $branch_id : null))->filter(fn ($row) => blank($search) || str_contains(strtolower($row['product'].' '.$row['category'].' '.$row['location']), strtolower($search))); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-2"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search product/category/location"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Items</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Quantity</p><p class="text-2xl font-black">{{ number_format((float) $rows->sum('quantity'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Stock Value</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('value'), 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Branch', 'Location', 'Product', 'Category', 'Qty', 'Avg Cost', 'Value']">@foreach ($rows as $row)<tr><td class="px-4 py-3">{{ $row['branch'] }}</td><td class="px-4 py-3">{{ $row['location'] }}</td><td class="px-4 py-3 font-bold">{{ $row['product'] }}</td><td class="px-4 py-3">{{ $row['category'] }}</td><td class="px-4 py-3 text-right">{{ number_format($row['quantity'], 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format($row['average_cost'], 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format($row['value'], 2) }}</td></tr>@endforeach</x-table></x-card>
</div>
