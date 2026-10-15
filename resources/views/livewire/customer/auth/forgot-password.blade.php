<?php

use App\Services\CustomerPortalCredentialService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state(['phone' => '', 'submitted' => false, 'operation_key' => '']);

rules(['phone' => ['required', 'string', 'max:30']]);

mount(function () {
    $this->operation_key = (string) Str::uuid();
});

$recover = function (CustomerPortalCredentialService $credentials) {
    $this->validate();
    $key = 'customer-recovery:'.hash('sha256', preg_replace('/\D+/', '', $this->phone).'|'.request()->ip());
    if (! RateLimiter::tooManyAttempts($key, 3)) {
        RateLimiter::hit($key, 300);
        $credentials->requestRecovery($this->phone, $this->operation_key);
    }
    $this->submitted = true;
};

?>

<div class="flex min-h-screen items-center justify-center bg-slate-100 p-6 dark:bg-slate-950">
    <x-card title="{{ __('application.recover_customer_portal_password_cdf07701') }}" class="w-full max-w-lg">
        @if ($submitted)
            <p class="text-sm font-semibold text-emerald-700">{{ __('application.if_the_phone_is_registered_and_eligible_new_temporary_credentials_have_been_1b84c7dd') }}</p>
            <a href="{{ route('customer.login') }}" wire:navigate class="mt-5 inline-block font-black text-build-orange">{{ __('application.back_to_login_4b675616') }}</a>
        @else
            <p class="mb-6 text-sm text-slate-500">{{ __('application.enter_your_registered_phone_number_for_privacy_the_response_is_the_same_for_80b60ea3') }}</p>
            <form wire:submit="recover" class="space-y-4">
                <x-form-input label="{{ __('application.phone_number_ab25d61b') }}" name="phone" wire:model="phone" placeholder="0629 364 847" required autofocus />
                <button class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">{{ __('application.send_recovery_credentials_aada284d') }}</button>
            </form>
        @endif
    </x-card>
</div>
