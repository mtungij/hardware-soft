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

state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'session_date' => '', 'opening_cash' => '', 'notes' => '']);

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
    session()->flash('success', __('application.cashbook_session_opened_4d33f6a9'));
    $this->redirectRoute('cashbook.show', $session, navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.cashbook_cf8b3a51') }}" description="{{ __('application.open_reconcile_and_close_daily_branch_cash_sessions_0bafea87') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Cashbook' => null]" />

    @php
        $sessions = CashbookSession::with(['branch', 'openedBy', 'closedBy'])->latest('session_date')->paginate(12);
        $openToday = CashbookSession::whereDate('session_date', today())->where('status', 'open')->count();
    @endphp

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        @can('manage cashbook')
            <x-card title="{{ __('application.open_cashbook_df221b4a') }}">
                <form wire:submit="openSession" class="space-y-4">
                    <label class="block text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                    <x-form-input label="{{ __('application.session_date_855be482') }}" name="session_date" wire:model="session_date" type="date" required />
                    <x-money-input label="{{ __('application.opening_cash_b758840a') }}" name="opening_cash" wire:model="opening_cash" required />
                    <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.open_session_621b98b8') }}</button>
                </form>
            </x-card>
        @endcan

        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card><p class="text-sm text-slate-500">{{ __('application.open_today_0ed174a7') }}</p><p class="mt-2 text-2xl font-black">{{ $openToday }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.closed_sessions_bafdfcac') }}</p><p class="mt-2 text-2xl font-black">{{ CashbookSession::where('status', 'closed')->count() }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.total_difference_e3b7eec6') }}</p><p class="mt-2 text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money(CashbookSession::sum('difference')) }}</p></x-card>
            </div>
            <x-card title="{{ __('application.sessions_e11e37a9') }}">
                <x-table :headers="[__('application.date_eb9a4bc1'), __('application.branch_1627510b'), __('application.expected_6ac87f34'), __('application.actual_7bb2ee60'), __('application.difference_b16f1f66'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
                    @foreach ($sessions as $session)
                        <tr>
                            <td class="px-4 py-3">{{ $session->session_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                            <td class="px-4 py-3">{{ $session->branch?->name }}</td>
                            <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->expected_cash) }}</td>
                            <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($session->actual_cash) }}</td>
                            <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($session->difference) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $session->status === 'open' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ \App\Support\UiText::status($session->status) }}</span></td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('cashbook.show', $session) }}" wire:navigate class="text-sm font-bold text-build-orange">{{ __('application.view_69bd4ef9') }}</a></td>
                        </tr>
                    @endforeach
                </x-table>
                <div class="mt-4">{{ $sessions->links() }}</div>
            </x-card>
        </div>
    </div>
</div>
