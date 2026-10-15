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
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
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
        session()->flash('success', __('application.customer_created_successfully_portal_access_can_be_enabled_after_activation_9ce07232'));
    } elseif ($result['account']?->last_credentials_notification_id) {
        session()->flash('success', __('application.customer_created_successfully_portal_credentials_were_queued_for_whatsapp_d_1d6fbb2a'));
    } else {
        session()->flash('error', __('application.customer_and_portal_account_were_created_but_credentials_could_not_be_queue_2c5577e9'));
    }

    $this->redirectRoute('customers.index', navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.create_customer_2bd83aec') }}" description="{{ __('application.create_customer_master_data_for_future_cash_and_credit_sales_646d000a') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="{{ __('application.customer_name_75636316') }}" name="name" wire:model="name" required />
            <x-form-input label="{{ __('application.phone_77064d52') }}" name="phone" wire:model="phone" required />
            <x-form-input label="{{ __('application.email_optional_96d41250') }}" name="email" type="email" wire:model="email" />
            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
            <x-money-input label="{{ __('application.opening_balance_1a1a6305') }}" name="opening_balance" wire:model="opening_balance" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.customer_type_9f8a0a78') }}
                <select wire:model="customer_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="cash">{{ __('application.cash_758ec54e') }}</option>
                    <option value="credit">{{ __('application.credit_8a45d339') }}</option>
                    <option value="contractor">{{ __('application.contractor_76bb2328') }}</option>
                    <option value="wholesale">{{ __('application.wholesale_21c1aef4') }}</option>
                </select>
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.branch_1627510b') }}
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                    <option value="">{{ __('application.global_customer_20d38e15') }}</option>
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
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_customer_ffe9f842') }}</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
            </div>
        </form>
    </x-card>
</div>
