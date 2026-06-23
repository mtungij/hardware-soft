<?php

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Services\CashbookService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['branch_id' => '', 'session_date' => '', 'opening_cash' => '', 'notes' => '']);

rules([
    'branch_id' => ['required', 'exists:branches,id'],
    'session_date' => ['required', 'date'],
    'opening_cash' => ['required', 'numeric', 'min:0'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

mount(function () {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->session_date = today()->toDateString();
});

$openSession = function (CashbookService $cashbook) {
    $data = $this->validate();
    $session = $cashbook->openSession((int) $data['branch_id'], $data['session_date'], (float) $data['opening_cash'], auth()->id(), $data['notes'] ?: null);
    session()->flash('success', 'Cashbook session opened.');
    $this->redirectRoute('cashbook.show', $session, navigate: true);
};

?>

<div>
    <x-page-header title="Cashbook" description="Open, reconcile, and close daily branch cash sessions." :breadcrumbs="['Dashboard' => route('dashboard'), 'Cashbook' => null]" />

    @php
        $sessions = CashbookSession::with(['branch', 'openedBy', 'closedBy'])->latest('session_date')->paginate(12);
        $openToday = CashbookSession::whereDate('session_date', today())->where('status', 'open')->count();
    @endphp

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        @can('manage cashbook')
            <x-card title="Open Cashbook">
                <form wire:submit="openSession" class="space-y-4">
                    <label class="block text-sm font-bold">Branch<select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                    <x-form-input label="Session Date" name="session_date" wire:model="session_date" type="date" required />
                    <x-form-input label="Opening Cash" name="opening_cash" wire:model="opening_cash" type="number" step="0.01" required />
                    <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Open Session</button>
                </form>
            </x-card>
        @endcan

        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card><p class="text-sm text-slate-500">Open Today</p><p class="mt-2 text-2xl font-black">{{ $openToday }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">Closed Sessions</p><p class="mt-2 text-2xl font-black">{{ CashbookSession::where('status', 'closed')->count() }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">Total Difference</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) CashbookSession::sum('difference'), 2) }}</p></x-card>
            </div>
            <x-card title="Sessions">
                <x-table :headers="['Date', 'Branch', 'Expected', 'Actual', 'Difference', 'Status', 'Actions']">
                    @foreach ($sessions as $session)
                        <tr>
                            <td class="px-4 py-3">{{ $session->session_date?->format('M d, Y') }}</td>
                            <td class="px-4 py-3">{{ $session->branch?->name }}</td>
                            <td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->expected_cash, 2) }}</td>
                            <td class="px-4 py-3 text-right">TZS {{ number_format((float) $session->actual_cash, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $session->difference, 2) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $session->status === 'open' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ ucfirst($session->status) }}</span></td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('cashbook.show', $session) }}" wire:navigate class="text-sm font-bold text-build-orange">View</a></td>
                        </tr>
                    @endforeach
                </x-table>
                <div class="mt-4">{{ $sessions->links() }}</div>
            </x-card>
        </div>
    </div>
</div>
