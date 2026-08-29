<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\WhatsAppNotification;
use App\Services\CustomerPortalCredentialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'customer' => null,
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
    'whatsapp_debt_reminders_enabled' => true,
    'credential_operation_key' => '',
]);

mount(function (Customer $customer) {
    abort_if($customer->is_system_customer, 403);

    $this->customer = $customer;
    $this->branch_id = (string) $customer->branch_id;
    $this->name = $customer->name;
    $this->phone = $customer->phone;
    $this->email = $customer->email;
    $this->address = $customer->address;
    $this->region = $customer->region;
    $this->district = $customer->district;
    $this->customer_type = $customer->customer_type;
    $this->opening_balance = (string) $customer->opening_balance;
    $this->status = $customer->status;
    $this->whatsapp_debt_reminders_enabled = $customer->whatsapp_debt_reminders_enabled;
    $this->credential_operation_key = (string) Str::uuid();
});

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'district' => ['nullable', 'string', 'max:255'],
    'customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
    'whatsapp_debt_reminders_enabled' => ['boolean'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$save = function (CustomerPortalCredentialService $credentials) {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['credit_limit'] = 0;
    $phone = $validated['phone'];
    unset($validated['phone']);

    DB::transaction(function () use ($validated, $phone, $credentials): void {
        $this->customer->update($validated);
        $normalized = $credentials->updateLoginPhone($this->customer, $phone, auth()->user());
        CustomerAccount::withoutGlobalScopes()->where('customer_id', $this->customer->id)->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'phone' => $normalized,
            'login_phone' => $normalized,
        ]);
    });

    session()->flash('success', 'Customer updated successfully.');
    $this->redirectRoute('customers.index', navigate: true);
};

$enablePortal = function (CustomerPortalCredentialService $credentials) {
    abort_unless(auth()->user()->can('customers.manage_portal_access'), 403);
    $account = $credentials->enableOrReset($this->customer, auth()->user(), $this->credential_operation_key);
    $this->credential_operation_key = (string) Str::uuid();
    session()->flash($account->last_credentials_notification_id ? 'success' : 'error', $account->last_credentials_notification_id
        ? 'Portal access enabled. Credentials were queued for WhatsApp delivery.'
        : 'Portal access was enabled, but credentials could not be queued. Please retry.');
};

$resetPortalPassword = function (CustomerPortalCredentialService $credentials) {
    abort_unless(auth()->user()->can('customers.manage_portal_access'), 403);
    $account = $credentials->enableOrReset($this->customer, auth()->user(), $this->credential_operation_key, true);
    $this->credential_operation_key = (string) Str::uuid();
    session()->flash($account->last_credentials_notification_id ? 'success' : 'error', $account->last_credentials_notification_id
        ? 'A new temporary password was generated and queued for WhatsApp delivery.'
        : 'The password was reset, but credentials could not be queued. Please retry immediately.');
};

?>

<div>
    <x-page-header title="Edit Customer" description="Update customer contact, type, opening balance, and status." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Edit' => null]" />

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

            <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700">
                <input type="checkbox" wire:model="whatsapp_debt_reminders_enabled" class="rounded text-build-orange">
                Allow transactional WhatsApp debt reminders
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                Address
                <textarea wire:model="address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update Customer</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>

    <x-card title="Commercial Actions" class="mt-6">
        <p class="text-sm text-slate-500">Create a document or final sale directly for this customer, or review their history.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @can('quotations.create')
                <a href="{{ route('quotations.create', ['customer' => $customer->id, 'type' => 'quotation']) }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Create Quotation</a>
                <a href="{{ route('quotations.create', ['customer' => $customer->id, 'type' => 'proforma']) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">Create Proforma</a>
            @endcan
            @if(auth()->user()->can('sales.create') && auth()->user()->can('invoices.send'))
                <a href="{{ route('direct-sales.create', ['customer' => $customer->id]) }}" wire:navigate class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white">Create Final Sale</a>
            @endif
            @can('quotations.view')<a href="{{ route('quotations.index', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">View Quotations</a>@endcan
            @can('invoices.view')<a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">View Invoices</a>@endcan
        </div>
    </x-card>

    @php
        $portalAccount = CustomerAccount::withoutGlobalScopes()->where('customer_id', $customer->id)->oldest('id')->first();
        $credentialNotification = $portalAccount?->last_credentials_notification_id
            ? WhatsAppNotification::withoutGlobalScopes()->find($portalAccount->last_credentials_notification_id)
            : null;
    @endphp
    <x-card title="Portal Access" class="mt-6">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-black uppercase text-slate-400">Portal Access</dt><dd class="font-bold">{{ $portalAccount?->login_phone ? 'Enabled' : 'Disabled' }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">Login Phone</dt><dd class="font-bold">{{ $portalAccount?->login_phone ?: '-' }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">Credential Delivery</dt><dd class="font-bold">{{ $credentialNotification ? str($credentialNotification->status)->title() : 'Not queued' }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">Last Credentials Queued</dt><dd class="font-bold">{{ $credentialNotification?->created_at?->format('d M Y H:i') ?: '-' }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-slate-500">Passwords and password hashes are never displayed.</p>
        @can('customers.manage_portal_access')
            <div class="mt-4 flex flex-wrap gap-2">
                @if (! $portalAccount?->login_phone)
                    <button wire:click="enablePortal" wire:confirm="Enable portal access and send new credentials?" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white">Enable Portal Access</button>
                @else
                    <button wire:click="resetPortalPassword" wire:confirm="Invalidate the current password and send a new temporary password?" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">Reset &amp; Send Portal Password</button>
                @endif
            </div>
        @endcan
    </x-card>
</div>
