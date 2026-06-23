<?php

use App\Models\Branch;
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
    'setting' => null,
    'company_name' => '',
    'company_logo' => '',
    'logo_upload' => null,
    'company_phone' => '',
    'company_email' => '',
    'company_address' => '',
    'currency' => 'TZS',
    'receipt_footer_text' => '',
    'tax_enabled' => false,
    'default_branch_id' => '',
    'theme_color' => '#f97316',
]);

mount(function () {
    $this->setting = Setting::query()->first() ?: Setting::query()->create(['company_name' => 'Company']);

    $this->company_name = $this->setting->company_name;
    $this->company_logo = $this->setting->company_logo;
    $this->company_phone = $this->setting->company_phone;
    $this->company_email = $this->setting->company_email;
    $this->company_address = $this->setting->company_address;
    $this->currency = $this->setting->currency;
    $this->receipt_footer_text = $this->setting->receipt_footer_text;
    $this->tax_enabled = $this->setting->tax_enabled;
    $this->default_branch_id = (string) $this->setting->default_branch_id;
    $this->theme_color = $this->setting->theme_color;
});

rules([
    'company_name' => ['required', 'string', 'max:255'],
    'company_logo' => ['nullable', 'string', 'max:255'],
    'logo_upload' => ['nullable', 'image', 'max:2048'],
    'company_phone' => ['nullable', 'string', 'max:30'],
    'company_email' => ['nullable', 'email', 'max:255'],
    'company_address' => ['nullable', 'string', 'max:1000'],
    'currency' => ['required', 'string', 'max:10'],
    'receipt_footer_text' => ['nullable', 'string', 'max:1000'],
    'tax_enabled' => ['boolean'],
    'default_branch_id' => ['nullable', 'exists:branches,id'],
    'theme_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
]);

$save = function () {
    $validated = $this->validate();
    $validated['default_branch_id'] = $validated['default_branch_id'] ?: null;

    if ($this->logo_upload) {
        $oldLogo = $this->company_logo;
        $validated['company_logo'] = $this->logo_upload->store('company-logos', 'public');

        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }
    }

    unset($validated['logo_upload']);

    $this->setting->update($validated);

    $company = Company::query()->first() ?: new Company();
    $company->fill([
        'company_name' => $this->setting->company_name,
        'logo' => $this->setting->company_logo,
        'phone' => $this->setting->company_phone,
        'email' => $this->setting->company_email,
        'address' => $this->setting->company_address,
        'currency' => $this->setting->currency,
    ])->save();

    $this->company_logo = $this->setting->company_logo;
    $this->logo_upload = null;
    $this->dispatch('buildmart-theme-color-updated', color: $this->theme_color);
    $this->dispatch('hardex-brand-updated', name: $this->company_name, initials: $this->brandInitials($this->company_name), logoUrl: $this->company_logo ? asset('storage/'.$this->company_logo) : '');

    session()->flash('success', 'System settings saved.');
};

$brandInitials = function (?string $name): string {
    return collect(preg_split('/\s+/', trim($name ?: 'Hardex POS')))
        ->filter()
        ->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
        ->take(2)
        ->join('') ?: 'HP';
};

$removeLogo = function () {
    if ($this->company_logo && Storage::disk('public')->exists($this->company_logo)) {
        Storage::disk('public')->delete($this->company_logo);
    }

    $this->setting->update(['company_logo' => null]);
    Company::query()->first()?->update(['logo' => null]);
    $this->company_logo = '';
    $this->logo_upload = null;
    $this->dispatch('hardex-brand-updated', name: $this->company_name, initials: $this->brandInitials($this->company_name), logoUrl: '');

    session()->flash('success', 'Company logo removed.');
};

?>

<div>
    <x-page-header
        title="System Settings"
        description="Configure company identity, defaults, receipts, tax, and visual theme."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Company Name" name="company_name" wire:model="company_name" required />

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:row-span-2">
                <div class="flex items-start gap-4">
                    <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-white/5">
                        @if ($logo_upload)
                            <img src="{{ $logo_upload->temporaryUrl() }}" class="h-full w-full object-contain" alt="New company logo preview">
                        @elseif ($company_logo)
                            <img src="{{ asset('storage/'.$company_logo) }}" class="h-full w-full object-contain" alt="{{ $company_name }} logo">
                        @else
                            <span class="text-xs font-black uppercase text-slate-400">Logo</span>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <x-file-upload label="Company Logo" name="logo_upload" wire:model="logo_upload" accept="image/png,image/jpeg,image/webp" />
                        <p class="mt-2 text-xs font-medium text-slate-500">Upload PNG, JPG, or WEBP. Max size 2MB.</p>
                        <div wire:loading wire:target="logo_upload" class="mt-2 text-xs font-bold text-build-orange">Uploading logo...</div>

                        @if ($company_logo)
                            <button type="button" wire:click="removeLogo" wire:confirm="Remove the company logo?" class="mt-3 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-black text-red-600 dark:border-red-500/30 dark:text-red-300">
                                Remove Logo
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <x-form-input label="Company Phone" name="company_phone" wire:model="company_phone" />
            <x-form-input label="Company Email" name="company_email" type="email" wire:model="company_email" />
            <x-form-input label="Currency" name="currency" wire:model="currency" required />
            <x-form-input label="System Theme Color" name="theme_color" type="color" wire:model="theme_color" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Default Branch
                <select wire:model="default_branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">No default branch</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('default_branch_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                <input type="checkbox" wire:model="tax_enabled" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                Tax/VAT enabled
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                Company Address
                <textarea wire:model="company_address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                @error('company_address') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                Receipt Footer Text
                <textarea wire:model="receipt_footer_text" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                @error('receipt_footer_text') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <div class="md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Settings</button>
            </div>
        </form>
    </x-card>
</div>
