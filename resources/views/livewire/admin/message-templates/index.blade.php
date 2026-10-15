<?php

use App\Models\MessageTemplate;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'templateId' => null, 'name' => '', 'subject' => '', 'message' => '', 'priority' => 'normal', 'category' => '', 'is_active' => true, 'deletingId' => null]);

$resetForm = function () {
    $this->reset(['templateId', 'name', 'subject', 'message', 'category']);
    $this->priority = 'normal';
    $this->is_active = true;
    $this->resetValidation();
};

$openCreate = function () {
    $this->resetForm();
    $this->dispatch('open-modal', 'template-form');
};

$openEdit = function (int $id) {
    $template = MessageTemplate::findOrFail($id);
    $this->templateId = $template->id;
    $this->name = $template->name;
    $this->subject = $template->subject;
    $this->message = $template->message;
    $this->priority = $template->priority;
    $this->category = $template->category;
    $this->is_active = $template->is_active;
    $this->dispatch('open-modal', 'template-form');
};

$saveTemplate = function () {
    $validated = $this->validate([
        'name' => ['required', 'string', 'max:255'],
        'subject' => ['required', 'string', 'max:255'],
        'message' => ['required', 'string'],
        'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        'category' => ['nullable', 'string', 'max:255'],
        'is_active' => ['boolean'],
    ]);

    $template = $this->templateId ? MessageTemplate::findOrFail($this->templateId) : new MessageTemplate(['created_by' => auth()->id()]);
    $template->fill($validated)->save();

    session()->flash('success', __('application.message_template_saved_381bb080'));
    $this->resetForm();
    $this->dispatch('close-modal', 'template-form');
};

$confirmDelete = function (int $id) {
    $this->deletingId = $id;
    $this->dispatch('open-modal', 'delete-template');
};

$deleteTemplate = function () {
    MessageTemplate::findOrFail($this->deletingId)->delete();
    $this->deletingId = null;
    session()->flash('success', __('application.template_deleted_cb6c493f'));
    $this->dispatch('close-modal', 'delete-template');
};

$templates = computed(fn () => MessageTemplate::query()
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('subject', 'like', '%'.$this->search.'%')))
    ->latest()
    ->paginate(10));

?>

<div>
    <x-page-header title="{{ __('application.message_templates_282a801f') }}" description="{{ __('application.reusable_customer_message_text_for_reminders_promotions_and_notices_0ec01c62') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Message Templates' => null]">
        <button type="button" wire:click="openCreate" class="erp-btn-primary">{{ __('application.add_template_a4779ee4') }}</button>
    </x-page-header>

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input mb-4 max-w-md" placeholder="{{ __('application.search_templates_08b9a69c') }}">
        <x-table :headers="[__('application.name_709a2322'), __('application.subject_8d183dbd'), __('application.category_a3c686e7'), __('application.priority_886cbff9'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($this->templates as $template)
                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $template->name }}</td>
                    <td class="px-4 py-3">{{ $template->subject }}</td>
                    <td class="px-4 py-3">{{ $template->category ?: '-' }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($template->priority) }}</td>
                    <td class="px-4 py-3">{{ $template->is_active ? __('application.active_a733b809') : __('application.inactive_09af574c') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2">
                            <button type="button" wire:click="openEdit({{ $template->id }})" class="erp-btn-secondary px-3 py-1.5">{{ __('application.edit_5301648d') }}</button>
                            <button type="button" wire:click="confirmDelete({{ $template->id }})" class="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-semibold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_templates_found_326abcdd') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->templates->links() }}</div>
    </x-card>

    <x-modal name="template-form" maxWidth="2xl">
        <form wire:submit.prevent="saveTemplate" class="p-5">
            <h2 class="text-lg font-semibold">{{ $templateId ? __('terminology.edit_template_936260c5') : __('application.add_template_a4779ee4') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-form-input label="{{ __('application.template_name_48c5544f') }}" name="name" wire:model="name" required />
                <x-form-input label="{{ __('application.category_a3c686e7') }}" name="category" wire:model="category" />
                <x-form-input label="{{ __('application.subject_8d183dbd') }}" name="subject" wire:model="subject" required class="md:col-span-2" />
                <x-form-textarea label="{{ __('application.message_68f4145f') }}" name="message" wire:model="message" rows="7" required class="md:col-span-2" />
                <x-form-select label="{{ __('application.priority_886cbff9') }}" name="priority" wire:model="priority" required>
                    @foreach (['low', 'normal', 'high', 'urgent'] as $option)
                        <option value="{{ $option }}">{{ \App\Support\UiText::label($option) }}</option>
                    @endforeach
                </x-form-select>
                <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium dark:border-slate-700">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                    {{ __('application.active_template_ce765257') }}
                </label>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'template-form')" class="erp-btn-secondary">{{ __('application.cancel_77dfd213') }}</button>
                <button class="erp-btn-primary">{{ __('application.save_efc007a3') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="delete-template" maxWidth="md" :closeOnBackdrop="false">
        <div class="p-5">
            <h2 class="text-lg font-semibold">{{ __('application.confirm_delete_59e5bdd4') }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ __('application.are_you_sure_you_want_to_delete_this_template_9befdf07') }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-template')" class="erp-btn-secondary">{{ __('application.cancel_77dfd213') }}</button>
                <button type="button" wire:click="deleteTemplate" class="rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white">{{ __('application.delete_f6fdbe48') }}</button>
            </div>
        </div>
    </x-modal>
</div>
