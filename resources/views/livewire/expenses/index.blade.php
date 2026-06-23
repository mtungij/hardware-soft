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
    'branchFilter' => '',
    'date_from' => '',
    'date_to' => '',
    'editingId' => null,
    'branch_id' => '',
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
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->expense_date = now()->toDateString();
});

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
    session()->flash('success', 'Expense saved successfully.');
};

$delete = function (int $id) {
    Expense::findOrFail($id)->delete();
    session()->flash('success', 'Expense deleted.');
};

?>

<div>
    <x-page-header title="Expenses" description="Track rent, salaries, utilities, transport, and daily operating costs." :breadcrumbs="['Dashboard' => route('dashboard'), 'Expenses' => null]">
        <a href="{{ route('expense-categories.index') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Categories</a>
    </x-page-header>

    @php
        $expenses = Expense::query()
            ->with(['branch', 'category', 'paidBy'])
            ->when($search, fn ($query) => $query->where('reference_number', 'like', "%{$search}%")->orWhereHas('category', fn ($q) => $q->where('name', 'like', "%{$search}%")))
            ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
            ->when($date_from, fn ($query) => $query->whereDate('expense_date', '>=', $date_from))
            ->when($date_to, fn ($query) => $query->whereDate('expense_date', '<=', $date_to))
            ->latest()
            ->paginate(10);
        $total = (clone $expenses->getCollection())->sum('amount');
    @endphp

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        <x-card :title="$editingId ? 'Edit Expense' : 'Create Expense'">
            <form wire:submit="save" class="space-y-4">
                <label class="block text-sm font-bold">Branch
                    <select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-bold">Category
                    <select wire:model="expense_category_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">Select category</option>
                        @foreach (ExpenseCategory::where('status', 'active')->orderBy('name')->get() as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('expense_category_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <x-form-input label="Amount" name="amount" wire:model="amount" type="number" step="0.01" required />
                <label class="block text-sm font-bold">Payment Method
                    <select wire:model="payment_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank">Bank</option>
                    </select>
                </label>
                <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" />
                <x-form-input label="Expense Date" name="expense_date" wire:model="expense_date" type="date" required />
                <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Save Expense</button>
            </form>
        </x-card>

        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card><p class="text-sm text-slate-500">Filtered Expenses</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) $total, 2) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">This Month</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) Expense::whereMonth('expense_date', now()->month)->whereYear('expense_date', now()->year)->sum('amount'), 2) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">Today</p><p class="mt-2 text-2xl font-black">TZS {{ number_format((float) Expense::whereDate('expense_date', today())->sum('amount'), 2) }}</p></x-card>
            </div>
            <x-card>
                <div class="mb-4 grid gap-3 md:grid-cols-4">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search category/ref">
                    <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                    <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                </div>
                <x-table :headers="['Date', 'Category', 'Method', 'Amount', 'Paid By', 'Actions']">
                    @foreach ($expenses as $expense)
                        <tr>
                            <td class="px-4 py-3">{{ $expense->expense_date?->format('M d, Y') }}</td>
                            <td class="px-4 py-3 font-bold">{{ $expense->category?->name }}</td>
                            <td class="px-4 py-3">{{ str($expense->payment_method)->replace('_', ' ')->title() }}</td>
                            <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $expense->amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $expense->paidBy?->name }}</td>
                            <td class="px-4 py-3 text-right"><button wire:click="edit({{ $expense->id }})" class="text-sm font-bold text-build-orange">Edit</button><button wire:click="delete({{ $expense->id }})" wire:confirm="Delete expense?" class="ml-3 text-sm font-bold text-red-600">Delete</button></td>
                        </tr>
                    @endforeach
                </x-table>
                <div class="mt-4">{{ $expenses->links() }}</div>
            </x-card>
        </div>
    </div>
</div>
