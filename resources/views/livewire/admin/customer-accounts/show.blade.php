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
    session()->flash('success', 'Customer account approved.');
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
    session()->flash('success', 'Customer account suspended.');
};

?>

<div>
    <x-page-header title="Customer Account" :description="$account->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Accounts' => route('admin.customer-accounts.index'), $account->name => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-1">
            <div class="text-center">
                <img class="mx-auto h-20 w-20 rounded-2xl" src="https://ui-avatars.com/api/?name={{ urlencode($account->name) }}&background=0d2e50&color=fff" alt="">
                <h2 class="mt-4 text-xl font-black">{{ $account->name }}</h2>
                <p class="text-sm text-slate-500">{{ $account->login_phone ?: $account->phone }}</p>
                <span class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($account->status === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ str($account->status)->title() }}</span>
            </div>
            <div class="mt-6 flex gap-2">
                @if ($account->status !== 'active')<button wire:click="approve" class="flex-1 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">Approve</button>@endif
                @if ($account->status !== 'suspended')<button wire:click="suspend" wire:confirm="Suspend this account?" class="flex-1 rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">Suspend</button>@endif
            </div>
        </x-card>
        <x-card title="Linked Customer" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">Customer</dt><dd class="font-bold">{{ $account->customer?->name }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Phone</dt><dd class="font-bold">{{ $account->phone ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Branch</dt><dd class="font-bold">{{ $account->customer?->branch?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Approved By</dt><dd class="font-bold">{{ $account->approvedBy?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Last Login</dt><dd class="font-bold">{{ $account->last_login_at?->format('M d, Y H:i') ?: 'Never' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Portal Access</dt><dd class="font-bold">{{ $account->login_phone ? 'Enabled' : 'Disabled' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Login Phone</dt><dd class="font-bold">{{ $account->login_phone ?: '-' }}</dd></div>
                @php($credentialNotification = $account->last_credentials_notification_id ? WhatsAppNotification::withoutGlobalScopes()->find($account->last_credentials_notification_id) : null)
                <div><dt class="text-xs font-black uppercase text-slate-400">Credential Delivery</dt><dd class="font-bold">{{ $credentialNotification ? str($credentialNotification->status)->title() : 'Not queued' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Last Credentials Queued</dt><dd class="font-bold">{{ $credentialNotification?->created_at?->format('M d, Y H:i') ?: '-' }}</dd></div>
            </dl>
            <p class="mt-5 text-xs text-slate-500">Passwords and password hashes are never displayed.</p>
            @can('customers.manage_portal_access')
                <button wire:click="resetPortalPassword" wire:confirm="Generate a new temporary password and invalidate the old one?" class="mt-4 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">
                    {{ $account->login_phone ? 'Reset & Send Portal Password' : 'Enable Portal Access' }}
                </button>
            @endcan
        </x-card>
    </div>
</div>
