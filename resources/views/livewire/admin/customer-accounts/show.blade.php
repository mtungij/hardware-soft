<?php

use App\Models\CustomerAccount;
use App\Models\WhatsAppNotification;
use App\Services\CustomerPortalCredentialService;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['account' => null, 'credential_operation_key' => '']);

mount(function (CustomerAccount $customerAccount) {
    $this->account = $customerAccount->load(['customer.branch', 'approvedBy']);
    $this->credential_operation_key = (string) Str::uuid();
});

$approve = function () {
    $this->account->update(['status' => 'active', 'approved_at' => now(), 'approved_by' => auth()->id()]);
    $this->account->refresh();
    session()->flash('success', __('application.customer_account_approved_a47ac7ae'));
};

$resetPortalPassword = function (CustomerPortalCredentialService $credentials) {
    abort_unless(auth()->user()->can('customers.manage_portal_access'), 403);
    $this->account = $credentials->enableOrReset(
        $this->account->customer,
        auth()->user(),
        $this->credential_operation_key,
        (bool) $this->account->login_phone,
    )->load(['customer.branch', 'approvedBy']);
    $this->credential_operation_key = (string) Str::uuid();
    session()->flash($this->account->last_credentials_notification_id ? 'success' : 'error', $this->account->last_credentials_notification_id
        ? 'New portal credentials were queued for WhatsApp delivery.'
        : 'The credentials changed, but WhatsApp delivery could not be queued. Please retry immediately.');
};

$suspend = function () {
    $this->account->update(['status' => 'suspended']);
    $this->account->refresh();
    session()->flash('success', __('application.customer_account_suspended_7b29586b'));
};

?>

<div>
    <x-page-header title="{{ __('application.customer_account_2cb87ac2') }}" :description="$account->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Accounts' => route('admin.customer-accounts.index'), $account->name => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-1">
            <div class="text-center">
                <img class="mx-auto h-20 w-20 rounded-2xl" src="https://ui-avatars.com/api/?name={{ urlencode($account->name) }}&background=0d2e50&color=fff" alt="">
                <h2 class="mt-4 text-xl font-black">{{ $account->name }}</h2>
                <p class="text-sm text-slate-500">{{ $account->login_phone ?: $account->phone }}</p>
                <span class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($account->status === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ \App\Support\UiText::label($account->status) }}</span>
            </div>
            <div class="mt-6 flex gap-2">
                @if ($account->status !== 'active')<button wire:click="approve" class="flex-1 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">{{ __('application.approve_7b2c7f14') }}</button>@endif
                @if ($account->status !== 'suspended')<button wire:click="suspend" wire:confirm="{{ __('application.suspend_this_account_482b3bf6') }}" class="flex-1 rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">{{ __('application.suspend_b24247fb') }}</button>@endif
            </div>
        </x-card>
        <x-card title="{{ __('application.linked_customer_5df6548b') }}" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.customer_0e85749a') }}</dt><dd class="font-bold">{{ $account->customer?->displayName() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.phone_77064d52') }}</dt><dd class="font-bold">{{ $account->phone ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.branch_1627510b') }}</dt><dd class="font-bold">{{ $account->customer?->branch?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.approved_by_2b62a43b') }}</dt><dd class="font-bold">{{ $account->approvedBy?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.last_login_2df48ad2') }}</dt><dd class="font-bold">{{ $account->last_login_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: __('application.never_80c3052d') }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.portal_access_8345f2ab') }}</dt><dd class="font-bold">{{ $account->login_phone ? __('application.enabled_df174a3f') : __('application.disabled_f4f4473d') }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.login_phone_2598c85d') }}</dt><dd class="font-bold">{{ $account->login_phone ?: '-' }}</dd></div>
                @php($credentialNotification = $account->last_credentials_notification_id ? WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->find($account->last_credentials_notification_id) : null)
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.credential_delivery_20a27aca') }}</dt><dd class="font-bold">{{ $credentialNotification ? \App\Support\UiText::label($credentialNotification->status) : __('terminology.not_queued_b7f41e1e') }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.last_credentials_queued_e0fc7b03') }}</dt><dd class="font-bold">{{ $credentialNotification?->created_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?: '-' }}</dd></div>
            </dl>
            <p class="mt-5 text-xs text-slate-500">{{ __('application.passwords_and_password_hashes_are_never_displayed_ac3f1298') }}</p>
            @can('customers.manage_portal_access')
                <button wire:click="resetPortalPassword" wire:confirm="{{ __('application.generate_a_new_temporary_password_and_invalidate_the_old_one_90f728b4') }}" class="mt-4 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">
                    {{ $account->login_phone ? __('terminology.reset_send_portal_password_c64bba04') : __('application.enable_portal_access_1df2c2fc') }}
                </button>
            @endcan
        </x-card>
    </div>
</div>
