<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\WithFileUploads;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.auth');
uses([WithFileUploads::class]);

state([
    'step' => 1,
    'company_name' => '',
    'business_type' => 'Hardware Store',
    'tin_number' => '',
    'vrn_number' => '',
    'phone' => '',
    'whatsapp_number' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'country' => 'Tanzania',
    'logo_upload' => null,
    'description' => '',
    'currency' => 'TZS',
    'timezone' => 'Africa/Dar_es_Salaam',
    'language' => 'sw',
    'admin_name' => '',
    'admin_phone' => '',
    'admin_email' => '',
    'admin_password' => '',
    'admin_password_confirmation' => '',
    'admin_photo' => null,
    'branch_name' => 'Main Branch',
    'branch_code' => 'MAIN',
    'branch_phone' => '',
    'branch_email' => '',
    'branch_address' => '',
    'branch_region' => '',
    'branch_district' => '',
    'branch_manager_name' => '',
    'branch_status' => 'active',
    'branch_is_default' => true,
]);

$validationRules = fn () => [
    'company_name' => ['required', 'string', 'max:255'],
    'business_type' => ['required', 'string', 'max:255'],
    'tin_number' => ['nullable', 'string', 'max:100'],
    'vrn_number' => ['nullable', 'string', 'max:100'],
    'phone' => ['required', 'string', 'max:30'],
    'whatsapp_number' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:100'],
    'district' => ['nullable', 'string', 'max:100'],
    'country' => ['required', 'string', 'max:100'],
    'logo_upload' => ['nullable', 'image', 'max:2048'],
    'description' => ['nullable', 'string', 'max:1500'],
    'currency' => ['required', 'string', 'max:10'],
    'timezone' => ['required', 'string', 'max:100'],
    'language' => ['required', 'in:sw,en'],
    'admin_name' => ['required', 'string', 'max:255'],
    'admin_phone' => ['required', 'string', 'max:30'],
    'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
    'admin_password' => ['required', 'confirmed', Password::defaults()],
    'admin_photo' => ['nullable', 'image', 'max:2048'],
    'branch_name' => ['required', 'string', 'max:255'],
    'branch_code' => ['required', 'string', 'max:50'],
    'branch_phone' => ['nullable', 'string', 'max:30'],
    'branch_email' => ['nullable', 'email', 'max:255'],
    'branch_address' => ['nullable', 'string', 'max:1000'],
    'branch_region' => ['nullable', 'string', 'max:100'],
    'branch_district' => ['nullable', 'string', 'max:100'],
    'branch_manager_name' => ['nullable', 'string', 'max:255'],
    'branch_status' => ['required', 'in:active,inactive'],
    'branch_is_default' => ['boolean'],
];

rules(fn () => $this->validationRules());

$stepFields = function (int $step): array {
    return match ($step) {
        1 => ['company_name', 'business_type', 'tin_number', 'vrn_number', 'phone', 'whatsapp_number', 'email', 'address', 'region', 'district', 'country', 'logo_upload', 'description', 'currency', 'timezone', 'language'],
        2 => ['admin_name', 'admin_phone', 'admin_email', 'admin_password', 'admin_password_confirmation', 'admin_photo'],
        3 => ['branch_name', 'branch_code', 'branch_phone', 'branch_email', 'branch_address', 'branch_region', 'branch_district', 'branch_manager_name', 'branch_status', 'branch_is_default'],
        default => [],
    };
};

$next = function () {
    $rules = collect($this->validationRules())->only($this->stepFields($this->step))->all();
    $this->validate($rules);
    $this->step = min(4, $this->step + 1);
};

$back = function () {
    $this->step = max(1, $this->step - 1);
};

$goTo = function (int $step) {
    if ($step < $this->step) {
        $this->step = $step;
    }
};

$complete = function () {
    $data = $this->validate();

    DB::transaction(function () use ($data) {
        $logoPath = $this->logo_upload?->store('company-logos', 'public');
        $photoPath = $this->admin_photo?->store('profile-photos', 'public');

        $company = Company::query()->create([
            'company_name' => $data['company_name'],
            'business_type' => $data['business_type'],
            'tin_number' => $data['tin_number'] ?: null,
            'vrn_number' => $data['vrn_number'] ?: null,
            'phone' => $data['phone'],
            'whatsapp_number' => $data['whatsapp_number'],
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'region' => $data['region'] ?: null,
            'district' => $data['district'] ?: null,
            'country' => $data['country'],
            'logo' => $logoPath,
            'description' => $data['description'] ?: null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'language' => $data['language'],
        ]);

        Branch::query()->where('is_default', true)->update(['is_default' => false]);

        $branch = Branch::query()->updateOrCreate(
            ['code' => strtoupper($data['branch_code'] ?: 'MAIN')],
            [
                'name' => $data['branch_name'],
                'phone' => $data['branch_phone'] ?: $data['phone'],
                'email' => $data['branch_email'] ?: $data['email'],
                'address' => $data['branch_address'] ?: $data['address'],
                'region' => $data['branch_region'] ?: $data['region'],
                'district' => $data['branch_district'] ?: $data['district'],
                'manager_name' => $data['branch_manager_name'] ?: $data['admin_name'],
                'status' => $data['branch_status'],
                'is_default' => (bool) $data['branch_is_default'],
            ]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(\Database\Seeders\RolePermissionSeeder::class)->run();
        $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::where('guard_name', 'web')->get());

        $user = User::query()->create([
            'branch_id' => $branch->id,
            'name' => $data['admin_name'],
            'phone' => $data['admin_phone'],
            'email' => $data['admin_email'],
            'profile_photo' => $photoPath,
            'status' => 'active',
            'is_system_owner' => true,
            'password' => $data['admin_password'],
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        $setting = Setting::query()->first() ?: new Setting();
        $setting->fill([
            'company_name' => $company->company_name,
            'business_type' => $company->business_type,
            'tin_number' => $company->tin_number,
            'vrn_number' => $company->vrn_number,
            'company_logo' => $company->logo,
            'company_phone' => $company->phone,
            'whatsapp_number' => $company->whatsapp_number,
            'company_email' => $company->email,
            'company_address' => $company->address,
            'region' => $company->region,
            'district' => $company->district,
            'country' => $company->country,
            'business_description' => $company->description,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
            'language' => $company->language,
            'default_branch_id' => $branch->id,
            'theme_color' => '#f97316',
            'system_initialized' => true,
        ])->save();
    });

    session()->flash('success', 'System setup completed. Sign in with your Super Admin account.');
    $this->redirectRoute('login', navigate: false);
};

?>

<div class="min-h-screen bg-slate-100 px-4 py-8 text-slate-900 dark:bg-slate-950 dark:text-white">
    <div class="mx-auto max-w-6xl">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <div class="grid h-12 w-12 place-items-center overflow-hidden rounded-xl bg-white p-1.5 shadow-soft">
                    <img src="{{ asset('images/hardex.png') }}" alt="Hardex" class="h-full w-full object-contain">
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xl font-black text-navy-900 dark:text-white">Hardex Hardware ERP</p>
                    <p class="text-sm font-semibold text-slate-500">First system setup</p>
                </div>
            </div>
            <a href="{{ route('login') }}" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black text-slate-600 transition hover:border-build-orange dark:border-slate-700 dark:text-slate-200">
                Back to Login
            </a>
        </div>

        <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm font-black text-navy-900 dark:text-white">Step {{ $step }} of 4</p>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ [1 => 'Business', 2 => 'Owner', 3 => 'Branch', 4 => 'Review'][$step] }}</p>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-build-orange transition-all duration-300" style="width: {{ ($step / 4) * 100 }}%"></div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <aside class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-soft dark:border-slate-800 dark:bg-slate-900 lg:sticky lg:top-6 lg:self-start lg:overflow-visible lg:p-4">
                <div class="flex min-w-max gap-2 lg:block lg:min-w-0 lg:space-y-2">
                @foreach ([1 => 'Hardware Business Information', 2 => 'Super Admin Account', 3 => 'Branch Information', 4 => 'Review & Complete'] as $number => $label)
                    <button type="button" wire:click="goTo({{ $number }})" class="flex w-52 shrink-0 items-center gap-3 rounded-xl px-3 py-3 text-left transition lg:w-full {{ $step === $number ? 'bg-orange-50 text-build-orange dark:bg-orange-500/15' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-white/5' }}">
                        <span class="grid h-9 w-9 place-items-center rounded-lg {{ $step >= $number ? 'bg-build-orange text-white' : 'bg-slate-100 dark:bg-white/10' }}">{{ $number }}</span>
                        <span class="min-w-0 text-sm font-black leading-tight">{{ $label }}</span>
                    </button>
                @endforeach
                </div>
            </aside>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                @if ($step === 1)
                    <div>
                        <h1 class="text-2xl font-black">Hardware Business Information</h1>
                        <p class="mt-1 text-sm text-slate-500">Set the identity, contact details, branding, and localization defaults for this ERP.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Company Name" name="company_name" wire:model="company_name" required />
                            <x-form-input label="Business Type" name="business_type" wire:model="business_type" required />
                            <x-form-input label="TIN Number" name="tin_number" wire:model="tin_number" />
                            <x-form-input label="VRN Number" name="vrn_number" wire:model="vrn_number" />
                            <x-form-input label="Phone Number" name="phone" wire:model="phone" required />
                            <x-form-input label="WhatsApp Number" name="whatsapp_number" wire:model="whatsapp_number" required />
                            <x-form-input label="Email Address" name="email" wire:model="email" type="email" />
                            <x-form-input label="Region" name="region" wire:model="region" />
                            <x-form-input label="District" name="district" wire:model="district" />
                            <x-form-input label="Country" name="country" wire:model="country" required />
                            <x-form-input label="Default Currency" name="currency" wire:model="currency" required />
                            <x-form-input label="Timezone" name="timezone" wire:model="timezone" required />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Language
                                <select wire:model="language" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="sw">Kiswahili</option>
                                    <option value="en">English</option>
                                </select>
                            </label>
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Company Logo
                                <input wire:model="logo_upload" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                @error('logo_upload') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <div class="md:col-span-2"><x-form-textarea label="Physical Address" name="address" wire:model="address" rows="3" /></div>
                            <div class="md:col-span-2"><x-form-textarea label="Business Description" name="description" wire:model="description" rows="3" /></div>
                        </div>
                    </div>
                @elseif ($step === 2)
                    <div>
                        <h1 class="text-2xl font-black">Super Admin Account</h1>
                        <p class="mt-1 text-sm text-slate-500">Create the first system owner with unrestricted ERP access.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Full Name" name="admin_name" wire:model="admin_name" required />
                            <x-form-input label="Phone Number" name="admin_phone" wire:model="admin_phone" required />
                            <x-form-input label="Email Address" name="admin_email" wire:model="admin_email" type="email" required />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Profile Picture
                                <input wire:model="admin_photo" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                @error('admin_photo') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <x-form-input label="Password" name="admin_password" wire:model="admin_password" type="password" required />
                            <x-form-input label="Confirm Password" name="admin_password_confirmation" wire:model="admin_password_confirmation" type="password" required />
                        </div>
                    </div>
                @elseif ($step === 3)
                    <div>
                        <h1 class="text-2xl font-black">Branch Information</h1>
                        <p class="mt-1 text-sm text-slate-500">Create the first operational branch. If no branch exists, this becomes MAIN.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Branch Name" name="branch_name" wire:model="branch_name" required />
                            <x-form-input label="Branch Code" name="branch_code" wire:model="branch_code" required />
                            <x-form-input label="Phone Number" name="branch_phone" wire:model="branch_phone" />
                            <x-form-input label="Email" name="branch_email" wire:model="branch_email" type="email" />
                            <x-form-input label="Region" name="branch_region" wire:model="branch_region" />
                            <x-form-input label="District" name="branch_district" wire:model="branch_district" />
                            <x-form-input label="Manager Name" name="branch_manager_name" wire:model="branch_manager_name" />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Status
                                <select wire:model="branch_status" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                                <input type="checkbox" wire:model="branch_is_default" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                                Default Branch: Yes
                            </label>
                            <div class="md:col-span-2"><x-form-textarea label="Address" name="branch_address" wire:model="branch_address" rows="3" /></div>
                        </div>
                    </div>
                @else
                    <div>
                        <h1 class="text-2xl font-black">Review & Complete</h1>
                        <p class="mt-1 text-sm text-slate-500">Confirm the setup summary before initializing the ERP.</p>
                        <div class="mt-6 grid gap-4 lg:grid-cols-3">
                            <x-card title="Company Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Company</dt><dd>{{ $company_name }}</dd><dt class="font-black">Business Type</dt><dd>{{ $business_type }}</dd><dt class="font-black">Phone</dt><dd>{{ $phone }}</dd><dt class="font-black">WhatsApp</dt><dd>{{ $whatsapp_number }}</dd><dt class="font-black">Location</dt><dd>{{ trim($district.', '.$region.', '.$country, ', ') }}</dd></dl>
                            </x-card>
                            <x-card title="Super Admin Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Name</dt><dd>{{ $admin_name }}</dd><dt class="font-black">Email</dt><dd>{{ $admin_email }}</dd><dt class="font-black">Phone</dt><dd>{{ $admin_phone }}</dd><dt class="font-black">Role</dt><dd>Super Admin</dd></dl>
                            </x-card>
                            <x-card title="Branch Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Branch</dt><dd>{{ $branch_name }}</dd><dt class="font-black">Code</dt><dd>{{ $branch_code }}</dd><dt class="font-black">Manager</dt><dd>{{ $branch_manager_name ?: $admin_name }}</dd><dt class="font-black">Default</dt><dd>{{ $branch_is_default ? 'Yes' : 'No' }}</dd></dl>
                            </x-card>
                        </div>
                    </div>
                @endif

                <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 dark:border-slate-800 sm:flex-row sm:justify-between">
                    <button type="button" wire:click="back" @disabled($step === 1) class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-black disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700">Back</button>
                    @if ($step < 4)
                        <button type="button" wire:click="next" class="rounded-xl bg-build-orange px-5 py-3 text-sm font-black text-white" wire:loading.attr="disabled">Next</button>
                    @else
                        <button type="button" wire:click="complete" class="rounded-xl bg-build-orange px-5 py-3 text-sm font-black text-white" wire:loading.attr="disabled">
                            <span wire:loading.remove>Complete Setup</span>
                            <span wire:loading>Completing...</span>
                        </button>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
