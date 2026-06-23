<?php

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\MessageTemplate;
use App\Services\CustomerCommunicationService;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class, WithFileUploads::class]);

state([
    'search' => '',
    'messageId' => null,
    'customer_id' => '',
    'template_id' => '',
    'subject' => '',
    'message' => '',
    'attachment' => null,
    'existingAttachment' => null,
    'priority' => 'normal',
    'status' => 'draft',
    'deletingId' => null,
]);

$resetForm = function () {
    $this->reset(['messageId', 'customer_id', 'template_id', 'subject', 'message', 'attachment', 'existingAttachment']);
    $this->priority = 'normal';
    $this->status = 'draft';
    $this->resetValidation();
};

$openCreate = function () {
    $this->resetForm();
    $this->dispatch('open-modal', 'customer-message-form');
};

$openEdit = function (int $id) {
    $message = CustomerMessage::findOrFail($id);
    $this->messageId = $message->id;
    $this->customer_id = (string) $message->customer_id;
    $this->subject = $message->subject;
    $this->message = $message->message;
    $this->existingAttachment = $message->attachment;
    $this->priority = $message->priority;
    $this->status = $message->status;
    $this->dispatch('open-modal', 'customer-message-form');
};

$applyTemplate = function () {
    if (! $this->template_id) {
        return;
    }

    $template = MessageTemplate::find($this->template_id);

    if (! $template) {
        return;
    }

    $this->subject = $template->subject;
    $this->message = $template->message;
    $this->priority = $template->priority;
};

$saveMessage = function (string $saveStatus = 'draft') {
    $this->status = $saveStatus;
    $validated = $this->validate([
        'customer_id' => ['required', 'exists:customers,id'],
        'subject' => ['required', 'string', 'max:255'],
        'message' => ['required', 'string'],
        'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        'status' => ['required', Rule::in(['draft', 'sent'])],
    ]);

    $customer = Customer::with(['portalAccounts' => fn ($query) => $query->where('status', 'active')])->findOrFail($this->customer_id);
    $message = $this->messageId ? CustomerMessage::findOrFail($this->messageId) : new CustomerMessage();

    $message->fill([
        ...collect($validated)->except('attachment')->all(),
        'customer_account_id' => $customer->portalAccounts->first()?->id,
        'sent_by' => auth()->id(),
        'sent_at' => $saveStatus === 'sent' ? now() : $message->sent_at,
        'channels' => ['portal'],
    ]);

    if ($this->attachment instanceof TemporaryUploadedFile) {
        $message->attachment = $this->attachment->store('customer-messages/attachments', 'public');
    }

    $message->save();

    if ($saveStatus === 'sent') {
        app(CustomerCommunicationService::class)->sendCustomerMessage($message);
        session()->flash('success', 'Message sent to customer portal.');
    } else {
        session()->flash('success', 'Message saved as draft.');
    }

    $this->resetForm();
    $this->dispatch('close-modal', 'customer-message-form');
};

$confirmDelete = function (int $id) {
    $this->deletingId = $id;
    $this->dispatch('open-modal', 'delete-customer-message');
};

$deleteMessage = function () {
    CustomerMessage::findOrFail($this->deletingId)->delete();
    $this->deletingId = null;
    session()->flash('success', 'Message deleted.');
    $this->dispatch('close-modal', 'delete-customer-message');
};

$messages = computed(fn () => CustomerMessage::with(['customer', 'sender'])
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
        ->where('subject', 'like', '%'.$this->search.'%')
        ->orWhere('message', 'like', '%'.$this->search.'%')
        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))))
    ->latest()
    ->paginate(10));
$customers = computed(fn () => Customer::where('status', 'active')->orderBy('name')->get());
$templates = computed(fn () => MessageTemplate::where('is_active', true)->orderBy('name')->get());

?>

<div>
    <x-page-header title="Customer Messages" description="Send direct messages to customer portal users." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Customer Messages' => null]">
        <button type="button" wire:click="openCreate" class="erp-btn-primary">Send Message</button>
    </x-page-header>

    <x-card>
        <div class="mb-4">
            <input wire:model.live.debounce.300ms="search" class="erp-input max-w-md" placeholder="Search customer or message">
        </div>
        <x-table :headers="['Date', 'Customer', 'Subject', 'Priority', 'Status', 'Read', 'Actions']">
            @forelse ($this->messages as $row)
                <tr>
                    <td class="px-4 py-3">{{ $row->created_at?->format('M d, Y H:i') }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $row->customer?->name }}</td>
                    <td class="px-4 py-3">{{ $row->subject }}</td>
                    <td class="px-4 py-3">{{ ucfirst($row->priority) }}</td>
                    <td class="px-4 py-3">{{ ucfirst($row->status) }}</td>
                    <td class="px-4 py-3">{{ $row->read_at?->format('M d, Y H:i') ?: 'Unread' }}</td>
                    <td class="px-4 py-3">
                        <div class="hs-dropdown relative inline-flex [--placement:bottom-end] [--strategy:fixed]">
                            <button type="button" class="hs-dropdown-toggle rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700">Actions</button>
                            <div class="hs-dropdown-menu z-[90] mt-2 hidden min-w-40 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                @if ($row->status === 'draft')
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="openEdit({{ $row->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-white/5">Edit</button>
                                @endif
                                <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="confirmDelete({{ $row->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10">Delete</button>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No messages found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->messages->links() }}</div>
    </x-card>

    <x-modal name="customer-message-form" maxWidth="2xl" :closeOnBackdrop="false">
        <form class="flex min-h-full flex-col sm:max-h-[calc(100vh-3rem)]">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="text-lg font-semibold">{{ $messageId ? 'Edit Customer Message' : 'Send Customer Message' }}</h2>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-select label="Template" name="template_id" wire:model="template_id" wire:change="applyTemplate">
                        <option value="">No template</option>
                        @foreach ($this->templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-select label="Customer" name="customer_id" wire:model="customer_id" required>
                        <option value="">Select customer</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} - {{ $customer->phone }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input label="Subject" name="subject" wire:model="subject" required class="md:col-span-2" />
                    <x-form-textarea label="Message" name="message" wire:model="message" rows="7" required class="md:col-span-2" />
                    <x-form-input label="Attachment" name="attachment" type="file" wire:model="attachment" />
                    <x-form-select label="Priority" name="priority" wire:model="priority" required>
                        @foreach (['low', 'normal', 'high', 'urgent'] as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </x-form-select>
                </div>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'customer-message-form')" class="erp-btn-secondary">Cancel</button>
                <button type="button" wire:click="saveMessage('draft')" class="erp-btn-secondary">Save Draft</button>
                <button type="button" wire:click="saveMessage('sent')" class="erp-btn-primary">Send</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="delete-customer-message" maxWidth="md" :closeOnBackdrop="false">
        <div class="p-5">
            <h2 class="text-lg font-semibold">Confirm Delete</h2>
            <p class="mt-2 text-sm text-slate-500">Are you sure you want to delete this message?</p>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-customer-message')" class="erp-btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteMessage" class="rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white">Delete</button>
            </div>
        </div>
    </x-modal>
</div>
