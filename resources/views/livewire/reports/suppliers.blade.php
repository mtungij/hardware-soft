<?php

use App\Models\Supplier;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');
state(['search' => '']);

?>

<div>
    <x-page-header title="{{ __('application.supplier_balance_report_8964c2d2') }}" description="{{ __('application.supplier_outstanding_balances_and_purchase_exposure_bde400d9') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Suppliers' => null]"><x-export-actions export="reports.suppliers" :params="compact('search')" /></x-page-header>
    @php $accounting = app(AccountingService::class); $rows = Supplier::query()->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))->get()->map(fn ($supplier) => ['supplier' => $supplier, 'balance' => $accounting->supplierBalance($supplier)]); @endphp
    <x-card><input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="{{ __('application.search_supplier_90db644f') }}"></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">{{ __('application.suppliers_6f76bb2b') }}</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.outstanding_f8ee57ec') }}</p><p class="text-2xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($rows->sum('balance')) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.opening_56e44065') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum(fn ($row) => $row['supplier']->opening_balance)) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="[__('application.supplier_55edd462'), __('application.phone_77064d52'), __('application.opening_56e44065'), __('application.outstanding_f8ee57ec')]">@foreach ($rows as $row)<tr><td class="px-4 py-3 font-bold">{{ $row['supplier']->name }}</td><td class="px-4 py-3">{{ $row['supplier']->phone }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($row['supplier']->opening_balance) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($row['balance']) }}</td></tr>@endforeach</x-table></x-card>
</div>
