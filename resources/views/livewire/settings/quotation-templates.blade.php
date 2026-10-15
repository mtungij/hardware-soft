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
    <x-page-header title="{{ __('application.quotation_templates_192df021') }}" description="{{ __('application.choose_a_presentation_for_your_next_quotation_313de5fa') }}" :breadcrumbs="['Settings' => route('settings.index'), 'Document Templates' => null, 'Quotation Templates' => null]" />
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-navy-950">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ $this->company->company_name }}</p>
        <h2 class="mt-1 text-xl font-black">{{ __('application.current_default_6de293fe') }} {{ QuotationTemplateRegistry::saved($this->company->quotation_template_key)['name'] }}</h2>
        <p class="mt-2 text-sm text-slate-500">{{ __('application.preview_a_design_then_set_it_as_your_default_previously_saved_quotations_ke_978a3eff') }}</p>
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
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-lg font-black">{{ $template['name'] }}</h3>@if ($selected)<span class="badge-success">{{ __('application.current_default_4218af6b') }}</span>@endif</div>
                    <p class="mt-2 min-h-12 text-sm text-slate-500">{{ $template['description'] }}</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('settings.quotation-templates.preview', $template['key']) }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.preview_f1fbb2b4') }}</a>
                        <button wire:click="setDefault('{{ $template['key'] }}')" wire:loading.attr="disabled" @disabled($selected) class="rounded-xl bg-build-orange px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ $selected ? __('terminology.selected_9a976fc2') : __('terminology.set_as_default_344efdbc') }}</button>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</div>
