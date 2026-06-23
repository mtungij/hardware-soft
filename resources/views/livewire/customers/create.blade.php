<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Setting;
use App\Mail\CustomerPortalAccountCreatedMail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'customer_type' => 'cash',
    'credit_limit' => '0',
    'opening_balance' => '0',
    'status' => 'active',
]);

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:255'],
    'customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
    'credit_limit' => ['required', 'numeric', 'min:0'],
    'opening_balance' => ['required', 'numeric', 'min:0'],
    'status' => ['required', 'in:active,inactive'],
]);

$temporaryPassword = function (): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    return collect(range(1, 12))
        ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
        ->join('');
};

$portalUrl = function (): string {
    return rtrim((string) config('app.customer_portal_url', env('CUSTOMER_PORTAL_URL', 'https://customer.buildcore.site')), '/').'/customer/login';
};

$configureMailer = function (?Setting $settings): ?string {
    if (! $settings?->mail_host) {
        return null;
    }

    Config::set('mail.mailers.buildmart_smtp', [
        'transport' => 'smtp',
        'scheme' => $settings->mail_encryption === 'ssl' ? 'smtps' : 'smtp',
        'url' => null,
        'host' => $settings->mail_host,
        'port' => $settings->mail_port ?: 587,
        'username' => $settings->mail_username,
        'password' => $settings->mail_password,
        'timeout' => 30,
        'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
    ]);
    Config::set('mail.from.address', $settings->mail_from_email ?: config('mail.from.address'));
    Config::set('mail.from.name', $settings->mail_from_name ?: ($settings->company_name ?: config('app.name')));
    Mail::forgetMailers();

    return 'buildmart_smtp';
};

$save = function () {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $password = $this->temporaryPassword();
    $settings = Setting::query()->first();
    $company = Company::current();
    $portalUrl = $this->portalUrl();

    [$customer, $account] = DB::transaction(function () use ($validated, $password) {
        $customer = Customer::create($validated);

        $account = CustomerAccount::create([
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'password' => $password,
            'status' => 'active',
            'preferred_locale' => 'sw',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return [$customer, $account];
    });

    try {
        $mailer = $this->configureMailer($settings);
        $message = new CustomerPortalAccountCreatedMail($customer, $account, $password, $portalUrl, $company, $settings);

        $mailer
            ? Mail::mailer($mailer)->to($account->email)->send($message)
            : Mail::to($account->email)->send($message);

        session()->flash('success', 'Customer created successfully. A Swahili welcome email with customer portal instructions was sent to '.$account->email.'.');
    } catch (\Throwable $exception) {
        report($exception);

        session()->flash('error', 'Customer and portal account were created, but the email could not be sent: '.$exception->getMessage());
    }

    $this->redirectRoute('customers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Customer" description="Create customer master data for future cash and credit sales." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => route('customers.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Customer Name" name="name" wire:model="name" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" required />
            <x-form-input label="Email" name="email" type="email" wire:model="email" required />
            <x-form-input label="Region" name="region" wire:model="region" />
            <x-form-input label="Credit Limit" name="credit_limit" type="number" step="0.01" wire:model="credit_limit" required />
            <x-form-input label="Opening Balance" name="opening_balance" type="number" step="0.01" wire:model="opening_balance" required />

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

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                Address
                <textarea wire:model="address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Customer</button>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
