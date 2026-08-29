<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Company;
use App\Services\CustomerPortalCredentialService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state(['name' => '', 'phone' => '', 'business_name' => '', 'email' => '', 'region' => '', 'district' => '', 'password' => '', 'password_confirmation' => '', 'branch_name' => '', 'terms' => false]);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'business_name' => ['nullable', 'string', 'max:255'],
    'email' => ['nullable', 'email', 'max:255', 'unique:customer_accounts,email'],
    'region' => ['nullable', 'string', 'max:255'],
    'district' => ['nullable', 'string', 'max:255'],
    'password' => ['required', 'confirmed', Password::defaults()],
    'branch_name' => ['nullable', 'string', 'max:255'],
    'terms' => ['accepted'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$register = function (CustomerPortalCredentialService $credentials) {
    $data = $this->validate();
    $phone = $credentials->normalizePhone($data['phone']);

    $existingAccount = CustomerAccount::withoutGlobalScopes()->where('login_phone', $phone)->first();
    if ($existingAccount) {
        throw ValidationException::withMessages(['phone' => __('messages.auth.already_exists')]);
    }

    $customer = Customer::withoutGlobalScopes()
        ->whereIn('phone', [$phone, '+'.$phone, '0'.substr($phone, 3)])
        ->first();

    if ($customer?->portalAccounts()->exists()) {
        throw ValidationException::withMessages(['phone' => __('messages.auth.already_exists')]);
    }

    $account = DB::transaction(function () use ($data, $phone, $customer) {
        if (! $customer) {
            $company = Company::current();
            abort_unless($company, 503);
            $branchId = Branch::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->value('id')
                ?? Branch::withoutGlobalScopes()->where('company_id', $company->id)->value('id');
            $customer = Customer::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'name' => $data['business_name'] ?: $data['name'],
                'phone' => $phone,
                'email' => ($data['email'] ?? null) ?: null,
                'address' => $data['branch_name'],
                'region' => $data['region'] ?: null,
                'district' => $data['district'] ?: null,
                'customer_type' => 'credit',
                'credit_limit' => 0,
                'opening_balance' => 0,
                'balance_amount' => 0,
                'status' => 'active',
            ]);
        }

        return CustomerAccount::withoutGlobalScopes()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'name' => $data['name'],
            'phone' => $phone,
            'login_phone' => $phone,
            'email' => ($data['email'] ?? null) ?: null,
            'password' => $data['password'],
            'status' => 'pending',
            'preferred_locale' => app()->getLocale() ?: 'sw',
            'must_change_password' => false,
        ]);
    });

    Auth::guard('customer')->login($account);
    Session::regenerate();

    $this->redirectRoute('customer.pending', navigate: true);
};

?>

@php
    $company = \App\Models\Company::current();
    $companyName = $company?->company_name ?: 'Customer Portal';
    $companyLogo = $company?->logo;
@endphp

<div class="min-h-screen bg-slate-100 px-4 py-8 dark:bg-slate-950">
    <div class="mx-auto max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-soft dark:border-slate-800 dark:bg-slate-900 lg:grid lg:grid-cols-2">
        <div class="bg-navy-900 p-8 text-white">
            <img src="{{ $companyLogo ? asset('storage/'.$companyLogo) : asset('images/hardex.png') }}" class="h-16 w-16 rounded-2xl bg-white object-contain p-2" alt="{{ $companyName }}">
            <h1 class="mt-8 text-3xl font-black">{{ __('messages.auth.create_account') }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-300">{{ __('messages.auth.register_intro') }}</p>
            <div class="mt-8 space-y-3 text-sm font-semibold text-slate-200">
                <p>✓ {{ __('messages.auth.features.debts') }}</p>
                <p>✓ {{ __('messages.auth.features.receipts') }}</p>
                <p>✓ {{ __('messages.auth.features.deposits') }}</p>
                <p>✓ {{ __('messages.auth.features.statements') }}</p>
            </div>
        </div>
        <div class="p-6 sm:p-8">
            <form wire:submit="register" class="grid gap-4 sm:grid-cols-2">
                <x-form-input :label="__('messages.auth.full_name')" name="name" wire:model="name" required />
                <x-form-input :label="__('messages.auth.phone')" name="phone" wire:model="phone" required />
                <x-form-input :label="__('messages.auth.business_name')" name="business_name" wire:model="business_name" />
                <x-form-input :label="__('messages.auth.email').' (Optional)'" name="email" wire:model="email" type="email" />
                <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
                <x-form-input :label="__('messages.auth.password')" name="password" wire:model="password" type="password" required />
                <x-form-input :label="__('messages.auth.confirm_password')" name="password_confirmation" wire:model="password_confirmation" type="password" required />
                <div class="sm:col-span-2"><x-form-input :label="__('messages.auth.branch_location')" name="branch_name" wire:model="branch_name" /></div>
                <label class="flex gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300 sm:col-span-2">
                    <input wire:model="terms" type="checkbox" class="mt-1 rounded border-slate-300 text-build-orange focus:ring-build-orange">
                    {{ __('messages.auth.terms') }}
                </label>
                @error('terms') <p class="text-sm font-semibold text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white sm:col-span-2" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('messages.auth.create_account') }}</span><span wire:loading>{{ __('messages.auth.creating') }}</span>
                </button>
            </form>
            <p class="mt-6 text-center text-sm font-semibold text-slate-500">
                {{ __('messages.auth.already_registered') }}
                <a href="{{ route('customer.login') }}" wire:navigate class="font-black text-build-orange">{{ __('messages.auth.back_to_login') }}</a>
            </p>
        </div>
    </div>
</div>
