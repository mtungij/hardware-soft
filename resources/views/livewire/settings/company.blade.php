<?php

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithFileUploads::class]);

state([
    'company' => null,
    'company_name' => '',
    'business_type' => '',
    'tin_number' => '',
    'vrn_number' => '',
    'phone' => '',
    'whatsapp_number' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'country' => 'Tanzania',
    'logo' => '',
    'logo_upload' => null,
    'description' => '',
    'currency' => 'TZS',
    'timezone' => 'Africa/Dar_es_Salaam',
    'language' => 'sw',
]);

mount(function () {
    $this->company = Company::current();
    $setting = Setting::query()->first();

    $this->company_name = $this->company?->company_name ?: $setting?->company_name ?: '';
    $this->business_type = $this->company?->business_type ?: $setting?->business_type ?: 'Hardware Store';
    $this->tin_number = $this->company?->tin_number ?: $setting?->tin_number ?: '';
    $this->vrn_number = $this->company?->vrn_number ?: $setting?->vrn_number ?: '';
    $this->phone = $this->company?->phone ?: $setting?->company_phone ?: '';
    $this->whatsapp_number = $this->company?->whatsapp_number ?: $setting?->whatsapp_number ?: '';
    $this->email = $this->company?->email ?: $setting?->company_email ?: '';
    $this->address = $this->company?->address ?: $setting?->company_address ?: '';
    $this->region = $this->company?->region ?: $setting?->region ?: '';
    $this->district = $this->company?->district ?: $setting?->district ?: '';
    $this->country = $this->company?->country ?: $setting?->country ?: 'Tanzania';
    $this->logo = $this->company?->logo ?: $setting?->company_logo ?: '';
    $this->description = $this->company?->description ?: $setting?->business_description ?: '';
    $this->currency = $this->company?->currency ?: $setting?->currency ?: 'TZS';
    $this->timezone = $this->company?->timezone ?: $setting?->timezone ?: 'Africa/Dar_es_Salaam';
    $this->language = $this->company?->language ?: $setting?->language ?: 'sw';
});

rules([
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
]);

$save = function () {
    $data = $this->validate();
    $logoPath = $this->logo;

    if ($this->logo_upload) {
        $oldLogo = $this->logo;
        $logoPath = $this->logo_upload->store('company-logos', 'public');

        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }
    }

    $company = Company::query()->first() ?: new Company();
    $company->fill([
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
        'logo' => $logoPath ?: null,
        'description' => $data['description'] ?: null,
        'currency' => $data['currency'],
        'timezone' => $data['timezone'],
        'language' => $data['language'],
    ])->save();

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
    ])->save();

    $this->company = $company;
    $this->logo = $company->logo;
    $this->logo_upload = null;
    $initials = collect(preg_split('/\s+/', trim($company->company_name)))->filter()->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))->take(2)->join('') ?: 'CO';
    $this->dispatch('hardex-brand-updated', name: $company->company_name, initials: $initials, logoUrl: $company->logo ? asset('storage/'.$company->logo) : '');
    session()->flash('success', 'Company settings saved.');
};

$removeLogo = function () {
    if ($this->logo && Storage::disk('public')->exists($this->logo)) {
        Storage::disk('public')->delete($this->logo);
    }

    $this->logo = '';
    $this->logo_upload = null;

    if ($this->company) {
        $this->company->update(['logo' => null]);
    }

    Setting::query()->first()?->update(['company_logo' => null]);
    session()->flash('success', 'Company logo removed.');
};

?>

<div>
    <x-page-header
        title="Company Settings"
        description="Manage company identity, tax details, support contacts, and localization defaults."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'Company' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
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

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:row-span-2">
                <div class="flex items-start gap-4">
                    <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-white/5">
                        @if ($logo_upload)
                            <img src="{{ $logo_upload->temporaryUrl() }}" class="h-full w-full object-contain" alt="New company logo preview">
                        @elseif ($logo)
                            <img src="{{ asset('storage/'.$logo) }}" class="h-full w-full object-contain" alt="{{ $company_name }} logo">
                        @else
                            <span class="text-xs font-black uppercase text-slate-400">Logo</span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-file-upload label="Company Logo" name="logo_upload" wire:model="logo_upload" accept="image/png,image/jpeg,image/webp" />
                        <p class="mt-2 text-xs font-medium text-slate-500">PNG, JPG, or WEBP. Max 2MB.</p>
                        @if ($logo)
                            <button type="button" wire:click="removeLogo" wire:confirm="Remove the company logo?" class="mt-3 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-black text-red-600 dark:border-red-500/30 dark:text-red-300">Remove Logo</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="md:col-span-2"><x-form-textarea label="Physical Address" name="address" wire:model="address" rows="3" /></div>
            <div class="md:col-span-2"><x-form-textarea label="Business Description" name="description" wire:model="description" rows="3" /></div>

            <div class="md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save Company Settings</span>
                    <span wire:loading>Saving...</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
