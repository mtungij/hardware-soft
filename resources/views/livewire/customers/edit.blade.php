<?php

use App\Models\Branch;
use App\Models\Customer;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'customer' => null,
    'branch_id' => '',
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'customer_type' => 'cash',
    'credit_limit' => '0',
    'opening_balance' => '0',
    'status' => 'active',
]);

mount(function (Customer $customer) {
    $this->customer = $customer;
    $this->branch_id = (string) $customer->branch_id;
    $this->name = $customer->name;
    $this->phone = $customer->phone;
    $this->email = $customer->email;
    $this->address = $customer->address;
    $this->region = $customer->region;
    $this->customer_type = $customer->customer_type;
    $this->credit_limit = (string) $customer->credit_limit;
    $this->opening_balance = (string) $customer->opening_balance;
    $this->status = $customer->status;
});

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
    'credit_limit' => ['required', 'numeric', 'min:0'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
]);

$save = function () {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;

    $this->customer->update($validated);

    session()->flash('success', 'Customer updated successfully.');
    $this->redirectRoute('customers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Edit Customer" description="Update customer contact, type, credit limit, and status." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Edit' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Customer Name" name="name" wire:model="name" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" required />
            <x-form-input label="Email" name="email" type="email" wire:model="email" />
            <x-form-input label="Region" name="region" wire:model="region" />
            <x-money-input label="Credit Limit" name="credit_limit" wire:model="credit_limit" required />
            <x-money-input label="Opening Balance" name="opening_balance" wire:model="opening_balance" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Customer Type
                <select wire:model="customer_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="cash">Cash</option>
                    <option value="credit">Credit</option>
                    <option value="contractor">Contractor</option>
                    <option value="wholesale">Wholesale</option>
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Branch
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Global customer</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Status
                <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                Address
                <textarea wire:model="address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update Customer</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
