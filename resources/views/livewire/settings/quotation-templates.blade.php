<?php

use App\Models\Company;
use App\Support\QuotationTemplateRegistry;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;

layout('layouts.app');

$company = computed(fn () => Company::findOrFail(auth()->user()->company_id));
$setDefault = function (string $key): void {
    $user = auth()->user();
    abort_unless($user && ($user->hasAnyRole(['Super Admin', 'Admin']) || $user->can('company-settings.update')), 403);
    $template = QuotationTemplateRegistry::require($key);
    Company::findOrFail($user->company_id)->update(['quotation_template_key' => $template['key']]);
    unset($this->company);
    session()->flash('success', $template['name'].' is now your default quotation template.');
};
?>
<div>
    <x-page-header title="Quotation Templates" description="Choose a presentation for your next quotation." :breadcrumbs="['Settings' => route('settings.index'), 'Document Templates' => null, 'Quotation Templates' => null]" />
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-navy-950">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ $this->company->company_name }}</p>
        <h2 class="mt-1 text-xl font-black">Current default: {{ QuotationTemplateRegistry::saved($this->company->quotation_template_key)['name'] }}</h2>
        <p class="mt-2 text-sm text-slate-500">Preview a design, then set it as your default. Previously saved quotations keep their selected template.</p>
    </div>
    @error('quotation_template_key')<p role="alert" class="mb-4 text-red-600">{{ $message }}</p>@enderror
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (QuotationTemplateRegistry::all() as $template)
            @php($selected = QuotationTemplateRegistry::saved($this->company->quotation_template_key)['key'] === $template['key'])
            <article wire:key="template-{{ $template['key'] }}" class="overflow-hidden rounded-2xl border {{ $selected ? 'border-build-orange ring-2 ring-build-orange' : 'border-slate-200 dark:border-slate-700' }} bg-white shadow-sm dark:bg-navy-950">
                <a href="{{ route('settings.quotation-templates.preview', $template['key']) }}" target="_blank" rel="noopener" class="block bg-slate-100 p-5">
                    <img src="{{ asset($template['preview']) }}" alt="{{ $template['name'] }} quotation preview" loading="lazy" width="360" height="509" class="mx-auto w-full max-w-xs border border-slate-200 bg-white shadow-md">
                </a>
                <div class="p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-lg font-black">{{ $template['name'] }}</h3>@if ($selected)<span class="badge-success">CURRENT DEFAULT</span>@endif</div>
                    <p class="mt-2 min-h-12 text-sm text-slate-500">{{ $template['description'] }}</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('settings.quotation-templates.preview', $template['key']) }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Preview</a>
                        <button wire:click="setDefault('{{ $template['key'] }}')" wire:loading.attr="disabled" @disabled($selected) class="rounded-xl bg-build-orange px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ $selected ? 'Selected' : 'Set as Default' }}</button>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</div>
