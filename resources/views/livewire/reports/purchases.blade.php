<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () {
    $this->branch_id = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branch_id', $this->branch_id) ? (int) request('branch_id', $this->branch_id) : null) ?? '');
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->search = request('search', $this->search);
});

?>

<div>
    <x-page-header title="{{ __('application.purchase_report_4edd5c16') }}" description="{{ __('application.supplier_purchases_paid_amounts_and_outstanding_balances_97adcbe7') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Purchases' => null]"><x-export-actions export="reports.purchases" :params="compact('branch_id', 'date_from', 'date_to', 'search')" /></x-page-header>
    @php $rows = collect(app(FinancialReportService::class)->purchases($branch_id ? (int) $branch_id : null, $date_from, $date_to))->filter(fn ($purchase) => blank($search) || str_contains(strtolower($purchase->reference_number.' '.$purchase->supplier?->name), strtolower($search))); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-4"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="{{ __('application.search_bce06414') }}"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">{{ __('application.purchases_6c4e94f1') }}</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.total_b25928c6') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('total_amount')) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.balance_90eef613') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('balance_amount')) }}</p></x-card></div>
    <x-card class="mt-4">
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.reference_db1c7845'), __('application.supplier_55edd462'), __('terminology.product_ordered_qty_1e86af0b'), __('application.conversion_9151f843'), __('application.base_qty_ordered_1539aff2'), __('application.received_base_received_c7cc46af'), __('application.remaining_cc632b5e'), __('application.status_bae7d5be'), __('application.total_b25928c6'), __('application.paid_dc9d4584'), __('application.balance_90eef613')]">
            @foreach ($rows as $purchase)<tr>
                <td class="px-4 py-3">{{ $purchase->purchase_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $purchase->reference_number }}</td><td class="px-4 py-3">{{ $purchase->supplier?->name }}</td>
                <td class="px-4 py-3">@foreach($purchase->items as $item)<div>{{ $item->product?->displayName() }}: {{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}</div>@endforeach</td>
                <td class="px-4 py-3">@foreach($purchase->items as $item)<div>1 {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->purchaseFactor()) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</div>@endforeach</td>
                <td class="px-4 py-3">@foreach($purchase->items as $item)<div>{{ \App\Support\NumberFormatter::quantity($item->base_ordered_quantity ?? $item->stockQuantity((float) $item->ordered_quantity)) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</div>@endforeach</td>
                <td class="px-4 py-3">@foreach($purchase->items as $item)<div>{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }} {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} / {{ \App\Support\NumberFormatter::quantity($item->base_received_quantity ?? $item->stockQuantity((float) $item->received_quantity)) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</div>@endforeach</td>
                <td class="px-4 py-3">@foreach($purchase->items as $item)<div>{{ \App\Support\NumberFormatter::quantity($item->remainingQuantity()) }} {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}</div>@endforeach</td>
                <td class="px-4 py-3">{{ \App\Support\UiText::status($purchase->payment_status) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->paid_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</td>
            </tr>@endforeach
        </x-table>
    </x-card>
</div>
