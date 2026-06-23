<?php

use App\Models\Branch;
use App\Models\CashbookSession;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () { $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="Cashbook Report" description="Opening cash, cash in/out, expected, actual, and differences." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Cashbook' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $rows = CashbookSession::with('branch')->whereBetween('session_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->latest('session_date')->get(); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-4"><x-card><p class="text-sm text-slate-500">Sessions</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Cash In</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('cash_in'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Cash Out</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('cash_out'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Difference</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('difference'), 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Date', 'Branch', 'Opening', 'Cash In', 'Cash Out', 'Expected', 'Actual', 'Difference']">@foreach ($rows as $session)<tr><td class="px-4 py-3">{{ $session->session_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ $session->branch?->name }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->opening_cash, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->cash_in, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->cash_out, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->expected_cash, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->actual_cash, 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $session->difference, 2) }}</td></tr>@endforeach</x-table></x-card>
</div>
