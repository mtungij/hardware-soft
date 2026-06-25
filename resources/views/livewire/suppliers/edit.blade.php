<?php

use App\Models\Branch;
use App\Models\Supplier;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'supplier' => null,
    'branch_id' => '',
    'name' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'opening_balance' => '0',
    'status' => 'active',
]);

mount(function (Supplier $supplier) {
    $this->supplier = $supplier;
    $this->branch_id = (string) $supplier->branch_id;
    $this->name = $supplier->name;
    $this->contact_person = $supplier->contact_person;
    $this->phone = $supplier->phone;
    $this->email = $supplier->email;
    $this->address = $supplier->address;
    $this->region = $supplier->region;
    $this->opening_balance = (string) $supplier->opening_balance;
    $this->status = $supplier->status;
});

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'contact_person' => ['nullable', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
]);

$save = function () {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;

    $this->supplier->update($validated);

    session()->flash('success', 'Supplier updated successfully.');
    $this->redirectRoute('suppliers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Edit Supplier" description="Update supplier contact and balance baseline." :breadcrumbs="['Dashboard' => route('dashboard'), 'Suppliers' => route('suppliers.index'), 'Edit' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Supplier Name" name="name" wire:model="name" required />
            <x-form-input label="Contact Person" name="contact_person" wire:model="contact_person" />
            <x-form-input label="Phone" name="phone" wire:model="phone" required />
            <x-form-input label="Email" name="email" type="email" wire:model="email" />
            <x-form-input label="Region" name="region" wire:model="region" />
            <x-money-input label="Opening Balance" name="opening_balance" wire:model="opening_balance" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Branch
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Global supplier</option>
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
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update Supplier</button>
                <a href="{{ route('suppliers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
