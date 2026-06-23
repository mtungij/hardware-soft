<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state(['name' => '', 'phone' => '', 'business_name' => '', 'email' => '', 'password' => '', 'password_confirmation' => '', 'branch_name' => '', 'terms' => false]);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'business_name' => ['nullable', 'string', 'max:255'],
    'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email'],
    'password' => ['required', 'confirmed', Password::defaults()],
    'branch_name' => ['nullable', 'string', 'max:255'],
    'terms' => ['accepted'],
]);

$register = function () {
    $data = $this->validate();

    $customer = Customer::query()
        ->where('email', $data['email'])
        ->orWhere('phone', $data['phone'])
        ->first();

    if ($customer?->portalAccounts()->exists()) {
        throw ValidationException::withMessages(['email' => 'A customer portal account already exists for this customer.']);
    }

    if (! $customer) {
        $customer = Customer::create([
            'branch_id' => Branch::query()->where('status', 'active')->value('id') ?? Branch::query()->value('id'),
            'name' => $data['business_name'] ?: $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'address' => $data['branch_name'],
            'region' => null,
            'customer_type' => 'credit',
            'credit_limit' => 0,
            'opening_balance' => 0,
            'balance_amount' => 0,
            'status' => 'active',
        ]);
    }

    $account = CustomerAccount::create([
        'customer_id' => $customer->id,
        'name' => $data['name'],
        'phone' => $data['phone'],
        'email' => $data['email'],
        'password' => $data['password'],
        'status' => 'pending',
    ]);

    Auth::guard('customer')->login($account);
    request()->session()->regenerate();

    $this->redirectRoute('customer.pending', navigate: true);
};

?>

<div class="min-h-screen bg-slate-100 px-4 py-8 dark:bg-slate-950">
    <div class="mx-auto max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-soft dark:border-slate-800 dark:bg-slate-900 lg:grid lg:grid-cols-2">
        <div class="bg-navy-900 p-8 text-white">
            <img src="{{ asset('images/hardex.png') }}" class="h-16 w-16 rounded-2xl bg-white object-contain p-2" alt="Hardex">
            <h1 class="mt-8 text-3xl font-black">Create Customer Account</h1>
            <p class="mt-3 text-sm leading-6 text-slate-300">Register for portal access. A Hardex administrator will approve your account before full access is enabled.</p>
            <div class="mt-8 space-y-3 text-sm font-semibold text-slate-200">
                <p>✓ View debts and invoices</p>
                <p>✓ Upload payment receipts</p>
                <p>✓ Track deposit balances</p>
                <p>✓ Print statements</p>
            </div>
        </div>
        <div class="p-6 sm:p-8">
            <form wire:submit="register" class="grid gap-4 sm:grid-cols-2">
                <x-form-input label="Full Name" name="name" wire:model="name" required />
                <x-form-input label="Phone Number" name="phone" wire:model="phone" required />
                <x-form-input label="Business Name" name="business_name" wire:model="business_name" />
                <x-form-input label="Email Address" name="email" wire:model="email" type="email" required />
                <x-form-input label="Password" name="password" wire:model="password" type="password" required />
                <x-form-input label="Confirm Password" name="password_confirmation" wire:model="password_confirmation" type="password" required />
                <div class="sm:col-span-2"><x-form-input label="Branch Name / Location" name="branch_name" wire:model="branch_name" /></div>
                <label class="flex gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300 sm:col-span-2">
                    <input wire:model="terms" type="checkbox" class="mt-1 rounded border-slate-300 text-build-orange focus:ring-build-orange">
                    I agree to Terms & Conditions.
                </label>
                @error('terms') <p class="text-sm font-semibold text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white sm:col-span-2" wire:loading.attr="disabled">
                    <span wire:loading.remove>Create Account</span><span wire:loading>Creating...</span>
                </button>
            </form>
            <p class="mt-6 text-center text-sm font-semibold text-slate-500">
                Already registered?
                <a href="{{ route('customer.login') }}" wire:navigate class="font-black text-build-orange">Back to login</a>
            </p>
        </div>
    </div>
</div>
