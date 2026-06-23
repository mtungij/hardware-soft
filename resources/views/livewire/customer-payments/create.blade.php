<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state(['customer_id' => '', 'branch_id' => '', 'amount' => '', 'payment_method' => 'cash', 'reference_number' => '', 'payment_date' => '', 'notes' => '']);

rules([
    'customer_id' => ['required', 'exists:customers,id'],
    'branch_id' => ['required', 'exists:branches,id'],
    'amount' => ['required', 'numeric', 'gt:0'],
    'payment_method' => ['required', 'in:cash,mobile_money,bank'],
    'reference_number' => ['nullable', 'string', 'max:255'],
    'payment_date' => ['required', 'date'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

mount(function () {
    $this->customer_id = (string) request('customer_id', '');
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->payment_date = now()->toDateString();
});

$save = function (AccountingService $accounting) {
    $data = $this->validate();
    $customer = Customer::findOrFail($data['customer_id']);
    $accounting->receiveCustomerPayment($customer, $data, auth()->id());
    session()->flash('success', 'Customer payment recorded.');
    $this->redirectRoute('customer-balances.show', $customer, navigate: true);
};

?>

<div>
    <x-page-header title="Record Customer Payment" description="Reduce customer outstanding balance." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), 'Payment' => null]" />
    <x-card class="max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <label class="block text-sm font-bold">Customer
                <select wire:model.live="customer_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select customer</option>@foreach (Customer::orderBy('name')->get() as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-bold">Branch
                <select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            </label>
            <x-form-input label="Amount" name="amount" wire:model="amount" type="number" step="0.01" required />
            <label class="block text-sm font-bold">Payment Method<select wire:model="payment_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank">Bank</option></select></label>
            <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" />
            <x-form-input label="Payment Date" name="payment_date" wire:model="payment_date" type="date" required />
            <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Save Payment</button>
        </form>
    </x-card>
</div>
