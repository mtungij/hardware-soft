<?php

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Customer;
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
    'statusFilter' => '',
    'announcementId' => null,
    'title' => '',
    'message' => '',
    'image' => null,
    'attachment' => null,
    'existingImage' => null,
    'existingAttachment' => null,
    'priority' => 'normal',
    'visibility_type' => 'all_customers',
    'customer_ids' => [],
    'branch_ids' => [],
    'customer_group' => '',
    'publish_date' => '',
    'expiry_date' => '',
    'status' => 'draft',
    'viewingId' => null,
    'deletingId' => null,
]);

$visibilityOptions = fn () => [
    'all_customers' => 'All Customers',
    'selected_customers' => 'Selected Customers',
    'customer_group' => 'Customer Group',
    'customers_with_debt' => 'Customers With Debt',
    'customers_with_deposits' => 'Customers With Deposits',
    'selected_branches' => 'Selected Branch Customers',
    'vip_customers' => 'VIP Customers',
];

$resetForm = function () {
    $this->reset(['announcementId', 'title', 'message', 'image', 'attachment', 'existingImage', 'existingAttachment', 'customer_ids', 'branch_ids', 'customer_group', 'publish_date', 'expiry_date']);
    $this->priority = 'normal';
    $this->visibility_type = 'all_customers';
    $this->status = 'draft';
    $this->resetValidation();
};

$openCreate = function () {
    $this->resetForm();
    $this->dispatch('open-modal', 'announcement-form');
};

$openEdit = function (int $id) {
    $announcement = Announcement::findOrFail($id);
    $filters = $announcement->target_filters ?: [];

    $this->announcementId = $announcement->id;
    $this->title = $announcement->title;
    $this->message = $announcement->message;
    $this->existingImage = $announcement->image;
    $this->existingAttachment = $announcement->attachment;
    $this->priority = $announcement->priority;
    $this->visibility_type = $announcement->visibility_type;
    $this->customer_ids = $filters['customer_ids'] ?? [];
    $this->branch_ids = $filters['branch_ids'] ?? [];
    $this->customer_group = $filters['customer_group'] ?? '';
    $this->publish_date = $announcement->publish_date?->format('Y-m-d\TH:i') ?: '';
    $this->expiry_date = $announcement->expiry_date?->format('Y-m-d\TH:i') ?: '';
    $this->status = $announcement->status;

    $this->dispatch('open-modal', 'announcement-form');
};

$rules = function (): array {
    return [
        'title' => ['required', 'string', 'max:255'],
        'message' => ['required', 'string'],
        'image' => ['nullable', 'image', 'max:4096'],
        'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
        'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        'visibility_type' => ['required', Rule::in(array_keys($this->visibilityOptions()))],
        'customer_ids' => ['array'],
        'customer_ids.*' => ['integer', 'exists:customers,id'],
        'branch_ids' => ['array'],
        'branch_ids.*' => ['integer', 'exists:branches,id'],
        'customer_group' => ['nullable', Rule::in(['cash', 'credit', 'contractor', 'wholesale'])],
        'publish_date' => ['nullable', 'date'],
        'expiry_date' => ['nullable', 'date', 'after_or_equal:publish_date'],
        'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
    ];
};

$saveAnnouncement = function (string $saveStatus = 'draft') {
    $this->status = $saveStatus;
    $validated = $this->validate($this->rules());

    $announcement = $this->announcementId ? Announcement::findOrFail($this->announcementId) : new Announcement();
    $filters = [
        'customer_ids' => array_values(array_filter($this->customer_ids)),
        'branch_ids' => array_values(array_filter($this->branch_ids)),
        'customer_group' => $this->customer_group ?: null,
    ];

    $announcement->fill([
        ...collect($validated)->except(['image', 'attachment', 'customer_ids', 'branch_ids', 'customer_group'])->all(),
        'publish_date' => $this->publish_date ?: null,
        'expiry_date' => $this->expiry_date ?: null,
        'target_filters' => $filters,
        'created_by' => $announcement->exists ? $announcement->created_by : auth()->id(),
    ]);

    if ($this->image instanceof TemporaryUploadedFile) {
        $announcement->image = $this->image->store('announcements/images', 'public');
    }

    if ($this->attachment instanceof TemporaryUploadedFile) {
        $announcement->attachment = $this->attachment->store('announcements/attachments', 'public');
    }

    $announcement->save();

    if ($saveStatus === 'published') {
        $count = app(CustomerCommunicationService::class)->publishAnnouncement($announcement);
        session()->flash('success', "Announcement published to {$count} customers.");
    } else {
        session()->flash('success', 'Announcement saved as draft.');
    }

    $this->resetForm();
    $this->dispatch('close-modal', 'announcement-form');
};

$openView = function (int $id) {
    $this->viewingId = $id;
    $this->dispatch('open-modal', 'announcement-view');
};

$confirmDelete = function (int $id) {
    $this->deletingId = $id;
    $this->dispatch('open-modal', 'delete-announcement');
};

$deleteAnnouncement = function () {
    Announcement::findOrFail($this->deletingId)->delete();
    $this->deletingId = null;
    session()->flash('success', 'Announcement deleted.');
    $this->dispatch('close-modal', 'delete-announcement');
};

$announcements = computed(fn () => Announcement::query()
    ->with('creator')
    ->withCount(['recipients', 'recipients as read_count' => fn ($query) => $query->where('is_read', true)])
    ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
    ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('title', 'like', '%'.$this->search.'%')->orWhere('message', 'like', '%'.$this->search.'%')))
    ->latest()
    ->paginate(10));

$viewingAnnouncement = computed(fn () => $this->viewingId ? Announcement::with(['creator'])->withCount(['recipients', 'recipients as read_count' => fn ($query) => $query->where('is_read', true)])->find($this->viewingId) : null);
$customers = computed(fn () => Customer::where('status', 'active')->orderBy('name')->get());
$branches = computed(fn () => Branch::orderBy('name')->get());

?>

<div>
    <x-page-header title="Announcements" description="Create promotions, reminders, and customer notices." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Communications' => null, 'Announcements' => null]">
        <button type="button" wire:click="openCreate" class="erp-btn-primary">Create Announcement</button>
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="Search announcements">
            <select wire:model.live="statusFilter" class="erp-input">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="archived">Archived</option>
            </select>
        </div>

        <x-table :headers="['Title', 'Priority', 'Visibility', 'Status', 'Recipients', 'Read', 'Publish Date', 'Actions']">
            @forelse ($this->announcements as $announcement)
                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $announcement->title }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $announcement->priority === 'urgent' ? 'bg-red-500/10 text-red-500' : ($announcement->priority === 'high' ? 'bg-amber-500/10 text-amber-500' : 'bg-cyan-500/10 text-cyan-500') }}">{{ ucfirst($announcement->priority) }}</span></td>
                    <td class="px-4 py-3">{{ $this->visibilityOptions()[$announcement->visibility_type] ?? $announcement->visibility_type }}</td>
                    <td class="px-4 py-3">{{ ucfirst($announcement->status) }}</td>
                    <td class="px-4 py-3">{{ number_format($announcement->recipients_count) }}</td>
                    <td class="px-4 py-3">{{ number_format($announcement->read_count) }}</td>
                    <td class="px-4 py-3">{{ $announcement->publish_date?->format('M d, Y H:i') ?: '-' }}</td>
                    <td class="px-4 py-3">
                        <div class="hs-dropdown relative inline-flex [--placement:bottom-end] [--strategy:fixed]">
                            <button type="button" class="hs-dropdown-toggle rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700">Actions</button>
                            <div class="hs-dropdown-menu z-[90] mt-2 hidden min-w-40 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="openView({{ $announcement->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-white/5">View</button>
                                <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="openEdit({{ $announcement->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-white/5">Edit</button>
                                <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="confirmDelete({{ $announcement->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10">Delete</button>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">No announcements found.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->announcements->links() }}</div>
    </x-card>

    <x-modal name="announcement-form" maxWidth="3xl" :closeOnBackdrop="false">
        <form wire:submit.prevent="saveAnnouncement('draft')" class="flex min-h-full flex-col sm:max-h-[calc(100vh-3rem)]">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="text-lg font-semibold">{{ $announcementId ? 'Edit Announcement' : 'Create Announcement' }}</h2>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-input label="Title" name="title" wire:model="title" required class="md:col-span-2" />
                    <x-form-textarea label="Message" name="message" wire:model="message" rows="6" required class="md:col-span-2" />
                    <x-form-input label="Image" name="image" type="file" wire:model="image" accept="image/*" />
                    <x-form-input label="Attachment PDF" name="attachment" type="file" wire:model="attachment" accept="application/pdf" />
                    <x-form-select label="Priority" name="priority" wire:model.live="priority" required>
                        @foreach (['low', 'normal', 'high', 'urgent'] as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-select label="Visibility" name="visibility_type" wire:model.live="visibility_type" required>
                        @foreach ($this->visibilityOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-form-select>
                    @if ($visibility_type === 'selected_customers')
                        <label class="erp-label md:col-span-2">Customers
                            <select wire:model="customer_ids" multiple class="erp-input mt-1 min-h-40">
                                @foreach ($this->customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }} - {{ $customer->phone }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    @if ($visibility_type === 'selected_branches')
                        <label class="erp-label md:col-span-2">Branches
                            <select wire:model="branch_ids" multiple class="erp-input mt-1 min-h-32">
                                @foreach ($this->branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    @if ($visibility_type === 'customer_group')
                        <x-form-select label="Customer Group" name="customer_group" wire:model="customer_group" required>
                            <option value="">Select group</option>
                            <option value="cash">Cash</option>
                            <option value="credit">Credit</option>
                            <option value="contractor">Contractor</option>
                            <option value="wholesale">Wholesale</option>
                        </x-form-select>
                    @endif
                    <x-form-input label="Publish Date" name="publish_date" type="datetime-local" wire:model="publish_date" />
                    <x-form-input label="Expiry Date" name="expiry_date" type="datetime-local" wire:model="expiry_date" />
                </div>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'announcement-form')" class="erp-btn-secondary">Cancel</button>
                <button type="button" wire:click="saveAnnouncement('draft')" wire:loading.attr="disabled" class="erp-btn-secondary">Save Draft</button>
                <button type="button" wire:click="saveAnnouncement('published')" wire:loading.attr="disabled" class="erp-btn-primary">Publish</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="announcement-view" maxWidth="2xl">
        @if ($this->viewingAnnouncement)
            <div class="p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $this->viewingAnnouncement->title }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ $this->viewingAnnouncement->creator?->name }} · {{ $this->viewingAnnouncement->created_at?->format('M d, Y H:i') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'announcement-view')" class="erp-btn-secondary px-2 py-1">Close</button>
                </div>
                @if ($this->viewingAnnouncement->image)
                    <img src="{{ asset('storage/'.$this->viewingAnnouncement->image) }}" class="mt-4 max-h-72 w-full rounded-xl object-cover" alt="">
                @endif
                <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $this->viewingAnnouncement->message }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <x-card><p class="text-xs text-slate-500">Recipients</p><p class="text-xl font-bold">{{ number_format($this->viewingAnnouncement->recipients_count) }}</p></x-card>
                    <x-card><p class="text-xs text-slate-500">Read</p><p class="text-xl font-bold">{{ number_format($this->viewingAnnouncement->read_count) }}</p></x-card>
                    <x-card><p class="text-xs text-slate-500">Unread</p><p class="text-xl font-bold">{{ number_format($this->viewingAnnouncement->recipients_count - $this->viewingAnnouncement->read_count) }}</p></x-card>
                </div>
            </div>
        @endif
    </x-modal>

    <x-modal name="delete-announcement" maxWidth="md" :closeOnBackdrop="false">
        <div class="p-5">
            <h2 class="text-lg font-semibold">Confirm Delete</h2>
            <p class="mt-2 text-sm text-slate-500">Are you sure you want to delete this announcement?</p>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-announcement')" class="erp-btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteAnnouncement" class="rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white">Delete</button>
            </div>
        </div>
    </x-modal>
</div>
