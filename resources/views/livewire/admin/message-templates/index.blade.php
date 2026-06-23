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

    session()->flash('success', 'Message template saved.');
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
    session()->flash('success', 'Template deleted.');
    $this->dispatch('close-modal', 'delete-template');
};

$templates = computed(fn () => MessageTemplate::query()
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('subject', 'like', '%'.$this->search.'%')))
    ->latest()
    ->paginate(10));

?>

<div>
    <x-page-header title="Message Templates" description="Reusable customer message text for reminders, promotions, and notices." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Message Templates' => null]">
        <button type="button" wire:click="openCreate" class="erp-btn-primary">Add Template</button>
    </x-page-header>

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input mb-4 max-w-md" placeholder="Search templates">
        <x-table :headers="['Name', 'Subject', 'Category', 'Priority', 'Status', 'Actions']">
            @forelse ($this->templates as $template)
                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $template->name }}</td>
                    <td class="px-4 py-3">{{ $template->subject }}</td>
                    <td class="px-4 py-3">{{ $template->category ?: '-' }}</td>
                    <td class="px-4 py-3">{{ ucfirst($template->priority) }}</td>
                    <td class="px-4 py-3">{{ $template->is_active ? 'Active' : 'Inactive' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2">
                            <button type="button" wire:click="openEdit({{ $template->id }})" class="erp-btn-secondary px-3 py-1.5">Edit</button>
                            <button type="button" wire:click="confirmDelete({{ $template->id }})" class="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-semibold text-white">Delete</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No templates found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->templates->links() }}</div>
    </x-card>

    <x-modal name="template-form" maxWidth="2xl">
        <form wire:submit.prevent="saveTemplate" class="p-5">
            <h2 class="text-lg font-semibold">{{ $templateId ? 'Edit Template' : 'Add Template' }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-form-input label="Template Name" name="name" wire:model="name" required />
                <x-form-input label="Category" name="category" wire:model="category" />
                <x-form-input label="Subject" name="subject" wire:model="subject" required class="md:col-span-2" />
                <x-form-textarea label="Message" name="message" wire:model="message" rows="7" required class="md:col-span-2" />
                <x-form-select label="Priority" name="priority" wire:model="priority" required>
                    @foreach (['low', 'normal', 'high', 'urgent'] as $option)
                        <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                    @endforeach
                </x-form-select>
                <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium dark:border-slate-700">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                    Active template
                </label>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'template-form')" class="erp-btn-secondary">Cancel</button>
                <button class="erp-btn-primary">Save</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="delete-template" maxWidth="md" :closeOnBackdrop="false">
        <div class="p-5">
            <h2 class="text-lg font-semibold">Confirm Delete</h2>
            <p class="mt-2 text-sm text-slate-500">Are you sure you want to delete this template?</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-template')" class="erp-btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteTemplate" class="rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white">Delete</button>
            </div>
        </div>
    </x-modal>
</div>
