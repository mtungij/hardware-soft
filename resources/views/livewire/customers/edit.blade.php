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
        CustomerAccount::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('customer_id', $this->customer->id)->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'phone' => $normalized,
            'login_phone' => $normalized,
        ]);
    });

    session()->flash('success', __('application.customer_updated_successfully_4f4039e0'));
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
    <x-page-header title="{{ __('application.edit_customer_ed6b5da6') }}" description="{{ __('application.update_customer_contact_type_opening_balance_and_status_888bd8d3') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Edit' => null]" />

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

            <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700">
                <input type="checkbox" wire:model="whatsapp_debt_reminders_enabled" class="rounded text-build-orange">
                {{ __('application.allow_transactional_whatsapp_debt_reminders_692bf895') }}
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                {{ __('application.address_d70f93df') }}
                <textarea wire:model="address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.update_customer_5ef580aa') }}</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
            </div>
        </form>
    </x-card>

    <x-card title="{{ __('application.commercial_actions_cd4a428c') }}" class="mt-6">
        <p class="text-sm text-slate-500">{{ __('application.create_a_document_or_final_sale_directly_for_this_customer_or_review_their__ec608105') }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @can('quotations.create')
                <a href="{{ route('quotations.create', ['customer' => $customer->id, 'type' => 'quotation']) }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.create_quotation_37ef31c2') }}</a>
                <a href="{{ route('quotations.create', ['customer' => $customer->id, 'type' => 'proforma']) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.create_proforma_ffc843fc') }}</a>
            @endcan
            @if(auth()->user()->can('sales.create') && auth()->user()->can('invoices.send'))
                <a href="{{ route('direct-sales.create', ['customer' => $customer->id]) }}" wire:navigate class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white">{{ __('application.create_final_sale_394d1c56') }}</a>
            @endif
            @can('quotations.view')<a href="{{ route('quotations.index', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.view_quotations_694467f1') }}</a>@endcan
            @can('invoices.view')<a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.view_invoices_382dde46') }}</a>@endcan
        </div>
    </x-card>

    @php
        $portalAccount = CustomerAccount::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('customer_id', $customer->id)->oldest('id')->first();
        $credentialNotification = $portalAccount?->last_credentials_notification_id
            ? WhatsAppNotification::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->find($portalAccount->last_credentials_notification_id)
            : null;
    @endphp
    <x-card title="{{ __('application.portal_access_8345f2ab') }}" class="mt-6">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.portal_access_8345f2ab') }}</dt><dd class="font-bold">{{ $portalAccount?->login_phone ? __('application.enabled_df174a3f') : __('application.disabled_f4f4473d') }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.login_phone_2598c85d') }}</dt><dd class="font-bold">{{ $portalAccount?->login_phone ?: '-' }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.credential_delivery_20a27aca') }}</dt><dd class="font-bold">{{ $credentialNotification ? \App\Support\UiText::label($credentialNotification->status) : __('terminology.not_queued_b7f41e1e') }}</dd></div>
            <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.last_credentials_queued_e0fc7b03') }}</dt><dd class="font-bold">{{ $credentialNotification?->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?: '-' }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-slate-500">{{ __('application.passwords_and_password_hashes_are_never_displayed_ac3f1298') }}</p>
        @can('customers.manage_portal_access')
            <div class="mt-4 flex flex-wrap gap-2">
                @if (! $portalAccount?->login_phone)
                    <button wire:click="enablePortal" wire:confirm="{{ __('application.enable_portal_access_and_send_new_credentials_63efaf8d') }}" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white">{{ __('application.enable_portal_access_1df2c2fc') }}</button>
                @else
                    <button wire:click="resetPortalPassword" wire:confirm="{{ __('application.invalidate_the_current_password_and_send_a_new_temporary_password_8a8af493') }}" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white">{{ __('application.reset_amp_send_portal_password_045efa50') }}</button>
                @endif
            </div>
        @endcan
    </x-card>
</div>
