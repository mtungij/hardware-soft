<?php

use App\Models\Branch;
use App\Services\CustomerPortalCredentialService;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'customer_type' => 'cash',
    'opening_balance' => '0',
    'status' => 'active',
    'operation_key' => '',
]);

mount(function () {
    $this->operation_key = (string) Str::uuid();
});

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255', 'unique:customer_accounts,email'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'district' => ['nullable', 'string', 'max:255'],
    'customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$save = function (CustomerPortalCredentialService $credentials) {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['credit_limit'] = 0;
    $result = $credentials->createCustomer($validated, auth()->user(), $this->operation_key);

    if ($validated['status'] !== 'active') {
        session()->flash('success', 'Customer created successfully. Portal access can be enabled after activation.');
    } elseif ($result['account']?->last_credentials_notification_id) {
        session()->flash('success', 'Customer created successfully. Portal credentials were queued for WhatsApp delivery.');
    } else {
        session()->flash('error', 'Customer and portal account were created, but credentials could not be queued. Use Reset & Send Portal Password to retry.');
    }

    $this->redirectRoute('customers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Customer" description="Create customer master data for future cash and credit sales." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Customer Name" name="name" wire:model="name" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" required />
            <x-form-input label="Email (Optional)" name="email" type="email" wire:model="email" />
            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
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
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Customer</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
