<?php

use App\Models\Branch;
use App\Models\CashbookSession;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () { $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="{{ __('application.cashbook_report_e9f0cb72') }}" description="{{ __('application.opening_cash_cash_in_out_expected_actual_and_differences_7e9a623f') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Cashbook' => null]"><x-export-actions export="reports.cashbook" :params="compact('branch_id', 'date_from', 'date_to', 'search')" /></x-page-header>
    @php $rows = CashbookSession::with('branch')->whereBetween('session_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->latest('session_date')->get(); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-4"><x-card><p class="text-sm text-slate-500">{{ __('application.sessions_e11e37a9') }}</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.cash_in_cf5d476c') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('cash_in')) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.cash_out_e6b54fb5') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('cash_out')) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.difference_b16f1f66') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('difference')) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="[__('application.date_eb9a4bc1'), __('application.branch_1627510b'), __('application.opening_56e44065'), __('application.cash_in_cf5d476c'), __('application.cash_out_e6b54fb5'), __('application.expected_6ac87f34'), __('application.actual_7bb2ee60'), __('application.difference_b16f1f66')]">@foreach ($rows as $session)<tr><td class="px-4 py-3">{{ $session->session_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3">{{ $session->branch?->name }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->opening_cash) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->cash_in) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->cash_out) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->expected_cash) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->actual_cash) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($session->difference) }}</td></tr>@endforeach</x-table></x-card>
</div>
