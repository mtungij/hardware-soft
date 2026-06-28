<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\StockLocation;
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
    'inventory_stock_mode' => 'warehouse',
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
    'inventory_stock_mode' => ['required', 'in:warehouse,direct'],
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

$updatedRegion = function () {
    $this->district = '';
};

$updatedBranchRegion = function () {
    $this->branch_district = '';
};

$stepFields = function (int $step): array {
    return match ($step) {
        1 => ['company_name', 'business_type', 'tin_number', 'vrn_number', 'phone', 'whatsapp_number', 'email', 'address', 'region', 'district', 'country', 'logo_upload', 'description', 'currency', 'timezone', 'language', 'inventory_stock_mode'],
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

        $dispensingLocation = StockLocation::query()->firstOrCreate(
            [
                'branch_id' => $branch->id,
                'code' => 'DISPENSING',
                'type' => 'dispensing',
            ],
            [
                'name' => 'Dispensing Area',
                'status' => 'active',
            ]
        );

        $mainStoreLocation = null;
        if ($data['inventory_stock_mode'] === 'warehouse') {
            $mainStoreLocation = StockLocation::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => 'MAIN-STORE',
                    'type' => 'store',
                ],
                [
                    'name' => 'Main Store',
                    'status' => 'active',
                ]
            );
        } else {
            StockLocation::query()
                ->where('branch_id', $branch->id)
                ->where('code', 'MAIN-STORE')
                ->update(['status' => 'inactive']);
        }

        $enableWarehouse = $data['inventory_stock_mode'] === 'warehouse';

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
            'enable_warehouse' => $enableWarehouse,
            'allow_direct_stock_in' => true,
            'allow_sales_from_store' => false,
            'default_stock_location_id' => $enableWarehouse ? $mainStoreLocation?->id : $dispensingLocation->id,
            'theme_color' => '#06b6d4',
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
                <div class="h-full rounded-full bg-cyan-500 transition-all duration-300" style="width: {{ ($step / 4) * 100 }}%"></div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <aside class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-soft dark:border-slate-800 dark:bg-slate-900 lg:sticky lg:top-6 lg:self-start lg:overflow-visible lg:p-4">
                <div class="flex min-w-max gap-2 lg:block lg:min-w-0 lg:space-y-2">
                @foreach ([1 => 'Hardware Business Information', 2 => 'Super Admin Account', 3 => 'Branch Information', 4 => 'Review & Complete'] as $number => $label)
                    <button type="button" wire:click="goTo({{ $number }})" class="flex w-52 shrink-0 items-center gap-3 rounded-xl px-3 py-3 text-left transition lg:w-full {{ $step === $number ? 'bg-cyan-50 text-cyan-600 dark:bg-cyan-500/15 dark:text-cyan-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-white/5' }}">
                        <span class="grid h-9 w-9 place-items-center rounded-lg {{ $step >= $number ? 'bg-cyan-500 text-white' : 'bg-slate-100 dark:bg-white/10' }}">{{ $number }}</span>
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
                            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
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
                         <div class="md:col-span-2">
  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-wide text-cyan-600 dark:text-cyan-300">
                Mfumo wa Kupokea na Kuuza Mzigo
            </p>

            <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-white">
                Unapokea mzigo kupitia ghala au moja kwa moja dukani?
            </h2>

            <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">
                Chagua mfumo unaofanana na namna hardware yako inavyofanya kazi kila siku.
            </p>
        </div>

        <span class="inline-flex w-fit items-center rounded-full bg-cyan-100 px-3 py-1 text-xs font-black text-cyan-900 dark:bg-cyan-500/20 dark:text-cyan-100">
            Lazima uchague
        </span>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">

        {{-- WITH WAREHOUSE --}}
        <label class="group cursor-pointer rounded-2xl border p-4 transition hover:border-cyan-400 hover:bg-cyan-50 dark:hover:border-cyan-400 dark:hover:bg-cyan-500/5 {{ $inventory_stock_mode === 'warehouse' ? 'border-cyan-500 bg-cyan-100 shadow-lg shadow-cyan-500/10 ring-2 ring-cyan-500/20 dark:border-cyan-400 dark:bg-cyan-500/10 dark:ring-cyan-400/20' : 'border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' }}">
            <input type="radio" wire:model.live="inventory_stock_mode" value="warehouse" class="sr-only">

            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 {{ $inventory_stock_mode === 'warehouse' ? 'border-cyan-600 bg-cyan-600 text-white dark:border-cyan-300 dark:bg-cyan-400 dark:text-slate-950' : 'border-slate-300 bg-white text-transparent group-hover:border-cyan-400 dark:border-slate-600 dark:bg-slate-900' }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.32a1 1 0 0 1-1.42 0L3.29 9.229a1 1 0 1 1 1.42-1.408l4.04 4.077 6.54-6.602a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-black text-slate-950 dark:text-white">
                                    Nina Ghala Kuu
                                </h3>

                               <span class="inline-flex items-center rounded-full bg-slate-900 px-3 py-1 text-xs font-black text-white dark:bg-white dark:text-slate-900">
    Hardware yenye Ghala Kuu
</span>
                            </div>

                            <p class="mt-3 text-sm font-bold leading-6 text-slate-800 dark:text-slate-100">
                                Chagua hii kama mzigo unaingia kwanza kwenye ghala/store kabla ya kupelekwa sehemu ya mauzo.
                            </p>
                        </div>

                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white shadow-lg shadow-cyan-500/20 dark:bg-cyan-400 dark:text-slate-950">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 21h18" />
                                <path d="M5 21V8l7-4 7 4v13" />
                                <path d="M9 21v-8h6v8" />
                                <path d="M9 10h.01" />
                                <path d="M15 10h.01" />
                            </svg>
                        </span>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-white">
                        <p class="text-sm font-black text-black">
                            Mfano:
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-extrabold text-black">
                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Mzigo unaingia Ghala Kuu
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unahamishiwa Sehemu ya Mauzo
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unauzwa kwa mteja
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </label>

        {{-- WITHOUT WAREHOUSE --}}
        <label class="group cursor-pointer rounded-2xl border p-4 transition hover:border-cyan-400 hover:bg-cyan-50 dark:hover:border-cyan-400 dark:hover:bg-cyan-500/5 {{ $inventory_stock_mode === 'direct' ? 'border-cyan-500 bg-cyan-100 shadow-lg shadow-cyan-500/10 ring-2 ring-cyan-500/20 dark:border-cyan-400 dark:bg-cyan-500/10 dark:ring-cyan-400/20' : 'border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' }}">
            <input type="radio" wire:model.live="inventory_stock_mode" value="direct" class="sr-only">

            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 {{ $inventory_stock_mode === 'direct' ? 'border-cyan-600 bg-cyan-600 text-white dark:border-cyan-300 dark:bg-cyan-400 dark:text-slate-950' : 'border-slate-300 bg-white text-transparent group-hover:border-cyan-400 dark:border-slate-600 dark:bg-slate-900' }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.32a1 1 0 0 1-1.42 0L3.29 9.229a1 1 0 1 1 1.42-1.408l4.04 4.077 6.54-6.602a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-black text-slate-950 dark:text-white">
                                    Sina Ghala Kuu
                                </h3>

                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-black text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/20 dark:text-amber-100">
                                    Inafaa kwa hardware ndogo
                                </span>
                            </div>

                            <p class="mt-3 text-sm font-bold leading-6 text-slate-800 dark:text-slate-100">
                                Chagua hii kama mzigo unaingia moja kwa moja kwenye eneo la mauzo na kuuzwa hapo hapo.
                            </p>
                        </div>

                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white shadow-lg shadow-cyan-500/20 dark:bg-cyan-400 dark:text-slate-950">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 10h16" />
                                <path d="M5 10l1-5h12l1 5" />
                                <path d="M6 10v9h12v-9" />
                                <path d="M9 19v-5h6v5" />
                            </svg>
                        </span>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-white">
                        <p class="text-sm font-black text-black">
                            Mfano:
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-extrabold text-black">
                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Mzigo unaingia Sehemu ya Mauzo
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unauzwa moja kwa moja kwa mteja
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </label>
    </div>

    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-bold leading-5 text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
        Unaweza kuanza na mfumo unaolingana na biashara yako. Mfumo utatumia chaguo hili kupanga namna stock inavyoingia na kuuzwa.
    </div>

    @error('inventory_stock_mode')
        <span class="mt-2 block text-xs font-semibold text-red-600">
            {{ $message }}
        </span>
    @enderror
</div>
</div>
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
                            <x-cropped-image-upload label="Profile Picture" name="admin_photo" wire:model="admin_photo" />
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
                            <x-tanzania-location-selects :region="$branch_region" :district="$branch_district" region-model="branch_region" district-model="branch_district" region-name="branch_region" district-name="branch_district" />
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
                                <dl class="space-y-2 text-sm"><dt class="font-black">Company</dt><dd>{{ $company_name }}</dd><dt class="font-black">Business Type</dt><dd>{{ $business_type }}</dd><dt class="font-black">Phone</dt><dd>{{ $phone }}</dd><dt class="font-black">WhatsApp</dt><dd>{{ $whatsapp_number }}</dd><dt class="font-black">Location</dt><dd>{{ trim($district.', '.$region.', '.$country, ', ') }}</dd><dt class="font-black">Inventory Stock Mode</dt><dd>{{ $inventory_stock_mode === 'warehouse' ? 'Nina Ghala Kuu' : 'Sina Ghala Kuu' }}</dd></dl>
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
                        <button type="button" wire:click="next" class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20" wire:loading.attr="disabled">Next</button>
                    @else
                        <button type="button" wire:click="complete" class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20" wire:loading.attr="disabled">
                            <span wire:loading.remove>Complete Setup</span>
                            <span wire:loading>Completing...</span>
                        </button>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
