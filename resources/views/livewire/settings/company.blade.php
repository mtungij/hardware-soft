<?php

use App\Models\Company;
use App\Models\Setting;
use App\Services\ProductionLocationService;
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
    'customer_portal_show_prices' => false,
    'company_name' => '',
    'business_type' => '',
    'tagline' => '',
    'tin_number' => '',
    'vrn_number' => '',
    'show_tax_identifiers_on_receipt' => false,
    'phone' => '',
    'alternate_phone' => '',
    'whatsapp_number' => '',
    'email' => '',
    'website' => '',
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
    'receipt_footer_message' => '',
    'manufacturing_enabled' => false,
    'default_raw_material_location_id' => '',
    'default_curing_location_id' => '',
    'default_finished_goods_location_id' => '',
]);

$authorizeCompanySettings = function (): void {
    $user = auth()->user();
    $authorized = $user && $user->company_id && $user->can('company-settings.update');

    abort_unless($authorized, 403);
};

mount(function () {
    $this->authorizeCompanySettings();
    $this->company = Company::current();
    $this->customer_portal_show_prices = (bool) $this->company?->customer_portal_show_prices;
    $companyId = $this->company?->id ?: auth()->user()?->company_id;
    $setting = Setting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)->first();

    $this->company_name = $this->company?->company_name ?: $setting?->company_name ?: '';
    $this->business_type = $this->company?->business_type ?: $setting?->business_type ?: 'Hardware Store';
    $this->tagline = $this->company?->tagline ?: $setting?->company_tagline ?: '';
    $this->tin_number = $this->company?->tin_number ?: $setting?->tin_number ?: '';
    $this->vrn_number = $this->company?->vrn_number ?: $setting?->vrn_number ?: '';
    $this->show_tax_identifiers_on_receipt = (bool) ($this->company?->show_tax_identifiers_on_receipt ?? $setting?->show_tax_identifiers_on_receipt ?? false);
    $this->phone = $this->company?->phone ?: $setting?->company_phone ?: '';
    $this->alternate_phone = $this->company?->alternate_phone ?: $setting?->alternate_phone ?: '';
    $this->whatsapp_number = $this->company?->whatsapp_number ?: $setting?->whatsapp_number ?: '';
    $this->email = $this->company?->email ?: $setting?->company_email ?: '';
    $this->website = $this->company?->website ?: $setting?->company_website ?: '';
    $this->address = $this->company?->address ?: $setting?->company_address ?: '';
    $this->region = $this->company?->region ?: $setting?->region ?: '';
    $this->district = $this->company?->district ?: $setting?->district ?: '';
    $this->country = $this->company?->country ?: $setting?->country ?: 'Tanzania';
    $this->logo = $this->company?->logo ?: $setting?->company_logo ?: '';
    $this->description = $this->company?->description ?: $setting?->business_description ?: '';
    $this->currency = $this->company?->currency ?: $setting?->currency ?: 'TZS';
    $this->timezone = $this->company?->timezone ?: $setting?->timezone ?: 'Africa/Dar_es_Salaam';
    $this->language = $this->company?->language ?: $setting?->language ?: 'sw';
    $this->receipt_footer_message = $setting?->receipt_footer_message ?: '';
    $this->manufacturing_enabled = $this->company?->manufacturingEnabled() ?? false;
    $this->default_raw_material_location_id = (string) ($setting?->default_raw_material_location_id ?: '');
    $this->default_curing_location_id = (string) ($setting?->default_curing_location_id ?: '');
    $this->default_finished_goods_location_id = (string) ($setting?->default_finished_goods_location_id ?: '');
});

rules([
    'customer_portal_show_prices' => ['boolean'],
    'company_name' => ['required', 'string', 'max:255'],
    'business_type' => ['required', 'string', 'max:255'],
    'tagline' => ['nullable', 'string', 'max:255'],
    'tin_number' => ['nullable', 'string', 'max:100'],
    'vrn_number' => ['nullable', 'string', 'max:100'],
    'show_tax_identifiers_on_receipt' => ['boolean'],
    'phone' => ['required', 'string', 'max:30'],
    'alternate_phone' => ['nullable', 'string', 'max:30'],
    'whatsapp_number' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'website' => ['nullable', 'string', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:100'],
    'district' => ['nullable', 'string', 'max:100'],
    'country' => ['required', 'string', 'max:100'],
    'logo_upload' => ['nullable', 'image', 'max:2048'],
    'description' => ['nullable', 'string', 'max:1500'],
    'currency' => ['required', 'string', 'max:10'],
    'timezone' => ['required', 'string', 'max:100'],
    'language' => ['required', 'in:sw,en'],
    'receipt_footer_message' => ['nullable', 'string', 'max:500'],
    'manufacturing_enabled' => ['boolean'],
    'default_raw_material_location_id' => ['nullable', 'integer'],
    'default_curing_location_id' => ['nullable', 'integer'],
    'default_finished_goods_location_id' => ['nullable', 'integer'],
]);

$updatedRegion = function () {
    $this->district = '';
};

$save = function () {
    $this->authorizeCompanySettings();
    $data = $this->validate();
    $logoPath = $this->logo;

    if ($this->logo_upload) {
        $oldLogo = $this->logo;
        $logoPath = $this->logo_upload->store('company-logos', 'public');

        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }
    }

    $company = $this->company ?: Company::current() ?: new Company();
    abort_unless(auth()->user()->is_system_owner || (int) $company->id === (int) auth()->user()->company_id, 403);
    $company->fill([
        'customer_portal_show_prices' => $data['customer_portal_show_prices'],
        'company_name' => $data['company_name'],
        'business_type' => $data['business_type'],
        'tagline' => $data['tagline'] ?: null,
        'tin_number' => $data['tin_number'] ?: null,
        'vrn_number' => $data['vrn_number'] ?: null,
        'show_tax_identifiers_on_receipt' => $data['show_tax_identifiers_on_receipt'],
        'phone' => $data['phone'],
        'alternate_phone' => $data['alternate_phone'] ?: null,
        'whatsapp_number' => $data['whatsapp_number'],
        'email' => $data['email'] ?: null,
        'website' => $data['website'] ?: null,
        'address' => $data['address'] ?: null,
        'region' => $data['region'] ?: null,
        'district' => $data['district'] ?: null,
        'country' => $data['country'],
        'logo' => $logoPath ?: null,
        'description' => $data['description'] ?: null,
        'currency' => $data['currency'],
        'timezone' => $data['timezone'],
        'language' => $data['language'],
        'manufacturing_enabled' => (bool) $data['manufacturing_enabled'],
    ])->save();

    $setting = Setting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
        ->where('company_id', $company->id)
        ->first() ?: new Setting(['company_id' => $company->id]);
    $canManageProductionDefaults = auth()->user()?->can('production.manage_location_defaults') ?? false;
    $productionDefaults = [];
    if ($canManageProductionDefaults) {
        $productionLocationService = app(ProductionLocationService::class);
        foreach ([
            ProductionLocationService::RAW => 'default_raw_material_location_id',
            ProductionLocationService::CURING => 'default_curing_location_id',
            ProductionLocationService::FINISHED => 'default_finished_goods_location_id',
        ] as $purpose => $field) {
            $productionDefaults[$field] = filled($data[$field])
                ? $productionLocationService->location((int) $data[$field], $purpose, $company->id, null, null, $field)->id
                : null;
        }
    }
    $setting->fill([
        'company_name' => $company->company_name,
        'business_type' => $company->business_type,
        'company_tagline' => $company->tagline,
        'tin_number' => $company->tin_number,
        'vrn_number' => $company->vrn_number,
        'show_tax_identifiers_on_receipt' => $company->show_tax_identifiers_on_receipt,
        'company_logo' => $company->logo,
        'company_phone' => $company->phone,
        'alternate_phone' => $company->alternate_phone,
        'whatsapp_number' => $company->whatsapp_number,
        'company_email' => $company->email,
        'company_website' => $company->website,
        'company_address' => $company->address,
        'region' => $company->region,
        'district' => $company->district,
        'country' => $company->country,
        'business_description' => $company->description,
        'currency' => $company->currency,
        'timezone' => $company->timezone,
        'language' => $company->language,
        'receipt_footer_message' => filled($data['receipt_footer_message'])
            ? $data['receipt_footer_message']
            : null,
        ...$productionDefaults,
    ])->save();

    $this->company = $company;
    $this->logo = $company->logo;
    $this->logo_upload = null;
    $initials = collect(preg_split('/\s+/', trim($company->company_name)))->filter()->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))->take(2)->join('') ?: 'CO';
    $this->dispatch('hardex-brand-updated', name: $company->company_name, initials: $initials, logoUrl: $company->logo ? asset('storage/'.$company->logo) : '');
    session()->flash('success', __('application.company_settings_saved_21306203'));
};

$removeLogo = function () {
    $this->authorizeCompanySettings();

    if ($this->logo && Storage::disk('public')->exists($this->logo)) {
        Storage::disk('public')->delete($this->logo);
    }

    $this->logo = '';
    $this->logo_upload = null;

    if ($this->company) {
        $this->company->update(['logo' => null]);
    }

    Setting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
        ->where('company_id', $this->company?->id)
        ->first()
        ?->update(['company_logo' => null]);
    session()->flash('success', __('application.company_logo_removed_3a22f36e'));
};

?>

<div><a href="{{ route('settings.customer-portal') }}" wire:navigate class="mb-4 inline-flex min-h-12 items-center rounded-xl border px-4 font-bold text-build-orange">{{ __('portal.price_settings') }}</a>
    <x-page-header
        title="{{ __('application.company_settings_183bb086') }}"
        description="{{ __('application.manage_company_identity_tax_details_support_contacts_and_localization_defau_e4a91f89') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'Company' => null]"
    />

    @php
        $canManageProductionDefaults = auth()->user()?->can('production.manage_location_defaults') ?? false;
        $productionLocationService = app(ProductionLocationService::class);
        $companyId = $company?->id ?: auth()->user()?->company_id;
        $rawDefaultLocations = $productionLocationService->eligibleLocations(ProductionLocationService::RAW, (int) $companyId, null);
        $curingDefaultLocations = $productionLocationService->eligibleLocations(ProductionLocationService::CURING, (int) $companyId, null);
        $finishedDefaultLocations = $productionLocationService->eligibleLocations(ProductionLocationService::FINISHED, (int) $companyId, null);
    @endphp
    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
        <label class="mb-4 flex min-h-12 items-center gap-3 rounded-xl border p-4 font-bold"><input type="checkbox" wire:model="customer_portal_show_prices">{{ __('portal.show_prices') }}</label>
            <x-form-input label="{{ __('application.company_name_8599f5cc') }}" name="company_name" wire:model="company_name" required />
            <x-form-input label="{{ __('application.business_type_29793523') }}" name="business_type" wire:model="business_type" required />
            <x-form-input label="{{ __('application.receipt_tagline_31cb7bc4') }}" name="tagline" wire:model="tagline" />
            <x-form-input label="{{ __('application.tin_number_17e784bb') }}" name="tin_number" wire:model="tin_number" />
            <x-form-input label="{{ __('application.vrn_vat_number_db19a62c') }}" name="vrn_number" wire:model="vrn_number" />
            <x-form-input label="{{ __('application.phone_number_ab25d61b') }}" name="phone" wire:model="phone" required />
            <x-form-input label="{{ __('application.alternate_phone_number_145953a1') }}" name="alternate_phone" wire:model="alternate_phone" />
            <x-form-input label="{{ __('application.whatsapp_number_51dacf06') }}" name="whatsapp_number" wire:model="whatsapp_number" required />
            <x-form-input label="{{ __('application.email_address_09ba557f') }}" name="email" wire:model="email" type="email" />
            <x-form-input label="{{ __('application.website_2e8a57cc') }}" name="website" wire:model="website" placeholder="www.example.co.tz" />
            <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                <input type="checkbox" wire:model="show_tax_identifiers_on_receipt" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                {{ __('application.show_tin_and_vat_number_on_customer_receipts_d5a5c0e1') }}
            </label>
            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
            <x-form-input label="{{ __('application.country_d523ebbd') }}" name="country" wire:model="country" required />
            <x-form-input label="{{ __('application.default_currency_bcfc9303') }}" name="currency" wire:model="currency" required />
            <x-form-input label="{{ __('application.timezone_d1f7dc89') }}" name="timezone" wire:model="timezone" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('application.language_89b86ab0') }}
                <select wire:model="language" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <option value="sw">{{ __('application.kiswahili_1fa52793') }}</option>
                    <option value="en">{{ __('application.english_649df08a') }}</option>
                </select>
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:col-span-2">
                <span>
                    <span class="block text-sm font-black text-slate-900 dark:text-white">{{ __('company.manufacturing.title') }}</span>
                    <span class="mt-1 block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('company.manufacturing.enable') }}</span>
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ __('company.manufacturing.description') }}</span>
                </span>
                <input
                    type="checkbox"
                    wire:model="manufacturing_enabled"
                    class="h-5 w-5 shrink-0 rounded border-slate-300 text-build-orange focus:ring-build-orange"
                >
            </label>

            <section class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/5 md:col-span-2">
                <h2 class="text-lg font-black text-slate-950 dark:text-white">{{ __('application.production_location_defaults_97dacdc2') }}</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __('application.new_production_orders_use_these_company_scoped_locations_branch_specific_lo_c0443841') }}</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    @foreach ([
                        ['field' => 'default_raw_material_location_id', 'label' => 'Default Raw Material Warehouse', 'locations' => $rawDefaultLocations],
                        ['field' => 'default_curing_location_id', 'label' => 'Default Curing Yard', 'locations' => $curingDefaultLocations],
                        ['field' => 'default_finished_goods_location_id', 'label' => 'Default Finished Goods Warehouse', 'locations' => $finishedDefaultLocations],
                    ] as $defaultField)
                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            {{ $defaultField['label'] }}
                            <select wire:model="{{ $defaultField['field'] }}" @disabled(! $canManageProductionDefaults) class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:disabled:bg-slate-800">
                                <option value="">{{ __('application.not_configured_811931bb') }}</option>
                                @foreach($defaultField['locations'] as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }} · {{ $location->code }} · {{ $location->branch?->name ?: __('application.company_wide_24f7fca3') }} · {{ \App\Support\UiText::label($location->type) }}</option>
                                @endforeach
                            </select>
                            @error($defaultField['field']) <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                @unless($canManageProductionDefaults)<p class="mt-3 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('application.you_do_not_have_permission_to_manage_production_location_defaults_d5d5fbf7') }}</p>@endunless
            </section>

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:row-span-2">
                <div class="flex items-start gap-4">
                    <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-white/5">
                        @if ($logo_upload)
                            <img src="{{ $logo_upload->temporaryUrl() }}" class="h-full w-full object-contain" alt="{{ __('application.new_company_logo_preview_580fa318') }}">
                        @elseif ($logo)
                            <img src="{{ asset('storage/'.$logo) }}" class="h-full w-full object-contain" alt="{{ $company_name }} logo">
                        @else
                            <span class="text-xs font-black uppercase text-slate-400">{{ __('application.logo_83fce832') }}</span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-file-upload label="{{ __('application.company_logo_b2d0bdaa') }}" name="logo_upload" wire:model="logo_upload" accept="image/png,image/jpeg,image/webp" />
                        <p class="mt-2 text-xs font-medium text-slate-500">{{ __('application.png_jpg_or_webp_max_2mb_dfe29a54') }}</p>
                        @if ($logo)
                            <button type="button" wire:click="removeLogo" wire:confirm="{{ __('application.remove_the_company_logo_d96961a8') }}" class="mt-3 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-black text-red-600 dark:border-red-500/30 dark:text-red-300">{{ __('application.remove_logo_587631f6') }}</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="md:col-span-2"><x-form-textarea label="{{ __('application.physical_address_b1e71fd3') }}" name="address" wire:model="address" rows="3" /></div>
            <div class="md:col-span-2"><x-form-textarea label="{{ __('application.business_description_b7a188bf') }}" name="description" wire:model="description" rows="3" /></div>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                {{ __('application.receipt_footer_message_64dfef2e') }}
                <textarea
                    wire:model="receipt_footer_message"
                    maxlength="500"
                    rows="4"
                    class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950"
                    placeholder="{{ __('application.enter_the_message_printed_below_customer_receipt_totals_b164b992') }}"
                ></textarea>
                <span class="mt-1 block text-xs font-medium text-slate-500">{{ __('application.optional_maximum_500_characters_line_breaks_are_preserved_on_receipts_206807aa') }}</span>
                @error('receipt_footer_message') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <div class="md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('application.save_company_settings_1d17aec9') }}</span>
                    <span wire:loading>{{ __('application.saving_ae7e8875') }}</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
