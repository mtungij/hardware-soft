<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'dateFilter' => 'today',
    'date_from' => '',
    'date_to' => '',
    'editingId' => null,
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'expense_category_id' => '',
    'amount' => '',
    'payment_method' => 'cash',
    'reference_number' => '',
    'expense_date' => '',
    'notes' => '',
]);

rules([
    'branch_id' => ['required', 'exists:branches,id'],
    'expense_category_id' => ['required', 'exists:expense_categories,id'],
    'amount' => ['required', 'numeric', 'gt:0'],
    'payment_method' => ['required', 'in:cash,mobile_money,bank'],
    'reference_number' => ['nullable', 'string', 'max:255'],
    'expense_date' => ['required', 'date'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

mount(function () {
    $this->search = request('search', $this->search);
    $this->branchFilter = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branchFilter', $this->branchFilter) ? (int) request('branchFilter', $this->branchFilter) : null) ?? '');
    $this->date_from = request('date_from', today()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->dateFilter = request('dateFilter', $this->dateFilter);
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->expense_date = now()->toDateString();
});

$setDateFilter = function (string $filter) {
    $this->dateFilter = $filter;

    match ($filter) {
        'today' => [
            $this->date_from = today()->toDateString(),
            $this->date_to = today()->toDateString(),
        ],
        'this_week' => [
            $this->date_from = now()->startOfWeek()->toDateString(),
            $this->date_to = now()->endOfWeek()->toDateString(),
        ],
        'this_month' => [
            $this->date_from = now()->startOfMonth()->toDateString(),
            $this->date_to = now()->endOfMonth()->toDateString(),
        ],
        default => null,
    };

    $this->resetPage();
};

$updatedDateFrom = function () {
    $this->dateFilter = 'custom';
    $this->resetPage();
};

$updatedDateTo = function () {
    $this->dateFilter = 'custom';
    $this->resetPage();
};

$updatedSearch = fn () => $this->resetPage();
$updatedBranchFilter = fn () => $this->resetPage();

$edit = function (int $id) {
    $expense = Expense::findOrFail($id);
    $this->editingId = $expense->id;
    $this->branch_id = (string) $expense->branch_id;
    $this->expense_category_id = (string) $expense->expense_category_id;
    $this->amount = (string) $expense->amount;
    $this->payment_method = $expense->payment_method;
    $this->reference_number = $expense->reference_number;
    $this->expense_date = $expense->expense_date->toDateString();
    $this->notes = $expense->notes;
};

$save = function () {
    $data = $this->validate();
    $data['paid_by'] = auth()->id();
    Expense::query()->updateOrCreate(['id' => $this->editingId], $data);
    $this->reset(['editingId', 'expense_category_id', 'amount', 'reference_number', 'notes']);
    $this->payment_method = 'cash';
    $this->expense_date = now()->toDateString();
    session()->flash('success', __('application.expense_saved_successfully_3a3571a8'));
};

$delete = function (int $id) {
    Expense::findOrFail($id)->delete();
    session()->flash('success', __('application.expense_deleted_b44fe386'));
};

?>

<div>
    <x-page-header title="{{ __('application.expenses_8d437dbf') }}" description="{{ __('application.track_rent_salaries_utilities_transport_and_daily_operating_costs_51ee1a75') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Expenses' => null]">
        <x-export-actions export="tables.expenses" :params="compact('search', 'branchFilter', 'dateFilter', 'date_from', 'date_to')" />
        <a href="{{ route('expense-categories.index') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.categories_6ccb6007') }}</a>
    </x-page-header>

    @php
        $expenseQuery = Expense::query()
            ->with(['branch', 'category', 'paidBy'])
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('reference_number', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))))
            ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
            ->when($date_from, fn ($query) => $query->whereDate('expense_date', '>=', $date_from))
            ->when($date_to, fn ($query) => $query->whereDate('expense_date', '<=', $date_to));
        $filteredTotal = (clone $expenseQuery)->sum('amount');
        $filteredCount = (clone $expenseQuery)->count();
        $cashTotal = (clone $expenseQuery)->where('payment_method', 'cash')->sum('amount');
        $expenses = $expenseQuery->latest()->paginate(10);
    @endphp

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        <x-card :title="$editingId ? __('terminology.edit_expense_2e469802') : __('terminology.create_expense_235ccbd1')">
            <form wire:submit="save" class="space-y-4">
                <label class="block text-sm font-bold">{{ __('application.branch_1627510b') }}
                    <select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-bold">{{ __('application.category_a3c686e7') }}
                    <select wire:model="expense_category_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ __('application.select_category_a2a5cafe') }}</option>
                        @foreach (ExpenseCategory::where('status', 'active')->orderBy('name')->get() as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('expense_category_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <x-money-input label="{{ __('application.amount_43dc8532') }}" name="amount" wire:model="amount" required />
                <label class="block text-sm font-bold">{{ __('application.payment_method_f383f6a2') }}
                    <select wire:model="payment_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">{{ __('application.cash_758ec54e') }}</option><option value="mobile_money">{{ __('application.mobile_money_6a38d1e4') }}</option><option value="bank">{{ __('application.bank_9e89988c') }}</option>
                    </select>
                </label>
                <x-form-input label="{{ __('application.reference_number_74195db5') }}" name="reference_number" wire:model="reference_number" />
                <x-form-input label="{{ __('application.expense_date_d64f2d6a') }}" name="expense_date" wire:model="expense_date" type="date" required />
                <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.save_expense_b89a5c08') }}</button>
            </form>
        </x-card>

        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card><p class="text-sm text-slate-500">{{ __('application.filtered_expenses_0dac4bb2') }}</p><p class="mt-2 text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($filteredTotal) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.entries_f056d0d5') }}</p><p class="mt-2 text-2xl font-black">{{ number_format($filteredCount) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.cash_expenses_2fb0f14c') }}</p><p class="mt-2 text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($cashTotal) }}</p></x-card>
            </div>
            <x-card>
                <div class="mb-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="setDateFilter('today')" class="rounded-lg px-3 py-2 text-sm font-black {{ $dateFilter === 'today' ? 'bg-build-orange text-white' : 'border border-slate-200 dark:border-slate-700' }}">{{ __('application.today_24345a14') }}</button>
                    <button type="button" wire:click="setDateFilter('this_week')" class="rounded-lg px-3 py-2 text-sm font-black {{ $dateFilter === 'this_week' ? 'bg-build-orange text-white' : 'border border-slate-200 dark:border-slate-700' }}">{{ __('application.this_week_4e68dca8') }}</button>
                    <button type="button" wire:click="setDateFilter('this_month')" class="rounded-lg px-3 py-2 text-sm font-black {{ $dateFilter === 'this_month' ? 'bg-build-orange text-white' : 'border border-slate-200 dark:border-slate-700' }}">{{ __('application.this_month_0f6cc3a8') }}</button>
                </div>
                <div class="mb-4 grid gap-3 md:grid-cols-4">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_category_ref_7953b709') }}">
                    <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                    <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                </div>
                <x-table :headers="[__('application.date_eb9a4bc1'), __('application.category_a3c686e7'), __('application.method_88306943'), __('application.amount_43dc8532'), __('application.paid_by_2e06e8e0'), __('application.actions_c3cd636a')]">
                    @foreach ($expenses as $expense)
                        <tr>
                            <td class="px-4 py-3">{{ $expense->expense_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                            <td class="px-4 py-3 font-bold">{{ $expense->category?->name }}</td>
                            <td class="px-4 py-3">{{ \App\Support\UiText::label($expense->payment_method) }}</td>
                            <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($expense->amount) }}</td>
                            <td class="px-4 py-3">{{ $expense->paidBy?->name }}</td>
                            <td class="px-4 py-3 text-right"><button wire:click="edit({{ $expense->id }})" class="text-sm font-bold text-build-orange">{{ __('application.edit_5301648d') }}</button><button wire:click="delete({{ $expense->id }})" wire:confirm="{{ __('application.delete_expense_6c0a3512') }}" class="ml-3 text-sm font-bold text-red-600">{{ __('application.delete_f6fdbe48') }}</button></td>
                        </tr>
                    @endforeach
                </x-table>
                <div class="mt-4">{{ $expenses->links() }}</div>
            </x-card>
        </div>
    </div>
</div>
