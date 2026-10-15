<?php

use App\Models\Branch;
use App\Models\Supplier;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'name' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'opening_balance' => '0',
    'status' => 'active',
]);

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'contact_person' => ['nullable', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'district' => ['nullable', 'string', 'max:255'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$save = function () {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;

    Supplier::create($validated);

    session()->flash('success', __('application.supplier_created_successfully_127e78f6'));
    $this->redirectRoute('suppliers.index', navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.create_supplier_6af8e34a') }}" description="{{ __('application.create_supplier_master_data_for_future_purchase_workflows_eecdefca') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Suppliers' => route('suppliers.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="{{ __('application.supplier_name_e2268c4a') }}" name="name" wire:model="name" required />
            <x-form-input label="{{ __('application.contact_person_649a4633') }}" name="contact_person" wire:model="contact_person" />
            <x-form-input label="{{ __('application.phone_77064d52') }}" name="phone" wire:model="phone" required />
            <x-form-input label="{{ __('application.email_84add5b2') }}" name="email" type="email" wire:model="email" />
            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
            <x-money-input label="{{ __('application.opening_balance_1a1a6305') }}" name="opening_balance" wire:model="opening_balance" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.branch_1627510b') }}
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                    <option value="">{{ __('application.global_supplier_b087a219') }}</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.status_bae7d5be') }}
                <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="active">{{ __('application.active_a733b809') }}</option>
                    <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                {{ __('application.address_d70f93df') }}
                <textarea wire:model="address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_supplier_a7c3e3ac') }}</button>
                <a href="{{ route('suppliers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
            </div>
        </form>
    </x-card>
</div>
