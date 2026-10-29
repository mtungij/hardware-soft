<?php

use App\Models\Company;
use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['company_id' => '', 'show_prices' => false]);
$authorizePortalCompany = function (int $companyId): void {
    $user = auth()->user();
    abort_unless($user && ($user->is_system_owner || ($user->can('company-settings.update') && (int) $user->company_id === $companyId)), 403);
};
$companies = computed(function () {
    $user = auth()->user();
    $this->authorizePortalCompany((int) $this->company_id);
    return Company::query()->when(! $user->is_system_owner, fn ($q) => $q->whereKey($user->company_id))->orderBy('company_name')->get();
});
$loadCompany = function (): void {
    $this->authorizePortalCompany((int) $this->company_id);
    $this->show_prices = (bool) Company::findOrFail($this->company_id)->customer_portal_show_prices;
};
mount(function () {
    $this->company_id = (string) (auth()->user()->company_id ?: Company::orderBy('id')->value('id'));
    $this->loadCompany();
});
$updatedCompanyId = function (): void { $this->loadCompany(); };
$save = function (): void {
    $this->validate(['company_id' => ['required', 'integer'], 'show_prices' => ['boolean']]);
    $this->authorizePortalCompany((int) $this->company_id);
    Company::findOrFail($this->company_id)->update(['customer_portal_show_prices' => $this->show_prices]);
    session()->flash('success', __('portal.saved'));
};
?>
<div class="mx-auto max-w-2xl">
    <x-page-header :title="__('portal.price_settings')" />
    <x-card><form wire:submit="save" class="space-y-5">
        <label class="block font-bold">{{ __('portal.company') }}<select wire:model.live="company_id" class="mt-2 min-h-12 w-full rounded-xl dark:bg-navy-950">@foreach($this->companies as $company)<option value="{{ $company->id }}">{{ $company->company_name }}</option>@endforeach</select></label>
        <label class="flex min-h-14 items-center gap-3 font-bold"><input type="checkbox" wire:model="show_prices">{{ __('portal.show_prices') }}</label>
        <button class="min-h-12 w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ __('portal.save') }}</button>
    </form></x-card>
</div>
