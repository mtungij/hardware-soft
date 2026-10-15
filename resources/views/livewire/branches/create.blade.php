<?php

use App\Models\Branch;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'name' => '',
    'code' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'status' => 'active',
]);

rules([
    'name' => ['required', 'string', 'max:255'],
    'code' => ['required', 'string', 'max:30', 'unique:branches,code'],
    'phone' => ['nullable', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'district' => ['nullable', 'string', 'max:255'],
    'status' => ['required', 'in:active,inactive'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$save = function () {
    Branch::create($this->validate());

    session()->flash('success', __('application.branch_created_successfully_c8fe89b6'));
    $this->redirectRoute('branches.index', navigate: true);
};

?>

<div>
    <x-page-header
        title="{{ __('application.create_branch_061f3327') }}"
        description="{{ __('application.create_a_business_branch_for_hardex_operations_42a9817e') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Branches' => route('branches.index'), 'Create' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="{{ __('application.branch_name_0e465da2') }}" name="name" wire:model="name" required />
            <x-form-input label="{{ __('application.branch_code_c36598ec') }}" name="code" wire:model="code" required />
            <x-form-input label="{{ __('application.phone_77064d52') }}" name="phone" wire:model="phone" />
            <x-form-input label="{{ __('application.email_84add5b2') }}" name="email" type="email" wire:model="email" />
            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.status_bae7d5be') }}
                <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="active">{{ __('application.active_a733b809') }}</option>
                    <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                </select>
                @error('status') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                {{ __('application.address_d70f93df') }}
                <textarea wire:model="address" class="mt-1 block min-h-28 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                @error('address') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_branch_91c10c31') }}</button>
                <a href="{{ route('branches.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
            </div>
        </form>
    </x-card>
</div>
