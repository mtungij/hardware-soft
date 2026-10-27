<?php
use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use App\Support\BranchAccess;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{layout, mount, hydrate, state, computed};
layout('layouts.app');
state(['method_id' => null])->locked();
state(['display_name' => '', 'type' => 'cash', 'account_name' => '', 'account_number' => '', 'instructions' => '', 'sort_order' => 0, 'branch_id' => '', 'is_active' => true, 'is_default' => false]);
$ensureAccess = function () { abort_unless(auth()->user()?->company_id && auth()->user()->can('payment_methods.view'), 403); };
mount(fn () => $this->ensureAccess());
hydrate(fn () => $this->ensureAccess());
$methods = computed(fn () => PaymentMethod::withoutGlobalScopes()->where('company_id', auth()->user()->company_id)->orderBy('sort_order')->orderBy('id')->get());
$resetForm = function () { $this->reset(['method_id', 'display_name', 'type', 'account_name', 'account_number', 'instructions', 'sort_order', 'branch_id', 'is_active', 'is_default']); $this->resetValidation(); };
$edit = function (int $id) {
    abort_unless(auth()->user()->can('payment_methods.manage'), 403);
    $method = PaymentMethod::withoutGlobalScopes()->where('company_id', auth()->user()->company_id)->findOrFail($id);
    $this->method_id = $id;
    foreach (['display_name', 'type', 'account_name', 'account_number', 'instructions', 'sort_order', 'branch_id', 'is_active', 'is_default'] as $field) { $this->{$field} = $method->{$field} ?? ''; }
};
$save = function (PaymentMethodService $service) {
    abort_unless(auth()->user()->can('payment_methods.manage'), 403);
    $data = $this->validate([
        'display_name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(PaymentMethod::TYPES)],
        'account_name' => ['nullable', 'string', 'max:255'], 'account_number' => ['nullable', 'string', 'max:255'],
        'instructions' => ['nullable', 'string', 'max:2000'], 'sort_order' => ['required', 'integer', 'min:0', 'max:99999'],
        'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', auth()->user()->company_id)],
        'is_active' => ['boolean'], 'is_default' => ['boolean'],
    ]);
    $data['branch_id'] = $data['branch_id'] ?: null;
    $service->save(auth()->user(), $this->method_id, $data);
    unset($this->methods);
    $this->resetForm();
    session()->flash('success', __('payments.saved'));
};
?>
<div>
    <x-page-header :title="__('payments.title')" :description="__('payments.description')" :breadcrumbs="['Settings' => route('settings.index'), __('payments.payments') => null, __('payments.title') => null]" />
    <div class="grid gap-6 lg:grid-cols-2">
        @can('payment_methods.manage')
        <x-card :title="$method_id ? __('payments.edit') : __('payments.new')">
            <form wire:submit="save" class="space-y-4">
                <x-form-input :label="__('payments.display_name')" name="display_name" wire:model="display_name" />
                <label class="block text-sm font-bold">{{ __('payments.type') }}<select wire:model="type" class="mt-1 w-full rounded-xl dark:bg-navy-950">@foreach(PaymentMethod::TYPES as $value)<option value="{{ $value }}">{{ __('payments.types.'.$value) }}</option>@endforeach</select></label>
                <x-form-input :label="__('payments.account_name')" name="account_name" wire:model="account_name" />
                <x-form-input :label="__('payments.account_number')" name="account_number" wire:model="account_number" />
                <label class="block text-sm font-bold">{{ __('payments.instructions') }}<textarea wire:model="instructions" class="mt-1 w-full rounded-xl dark:bg-navy-950"></textarea></label>
                <x-form-input :label="__('payments.sort_order')" name="sort_order" type="number" wire:model="sort_order" />
                <label class="block text-sm font-bold">{{ __('payments.branch_id') }}<select wire:model="branch_id" class="mt-1 w-full rounded-xl dark:bg-navy-950"><option value="">{{ __('payments.all_branches') }}</option>@foreach(BranchAccess::branches(auth()->user())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <div class="flex flex-wrap gap-4 text-sm font-bold">@foreach(['is_active', 'is_default'] as $field)<label><input type="checkbox" wire:model="{{ $field }}"> {{ __('payments.'.$field) }}</label>@endforeach</div>
                @foreach($errors->all() as $error)<p class="text-sm text-red-600">{{ $error }}</p>@endforeach
                <button class="rounded-xl bg-build-orange px-4 py-3 font-black text-white">{{ __('payments.save') }}</button>
                <button type="button" wire:click="resetForm" class="rounded-xl border px-4 py-3 font-bold">{{ __('payments.new') }}</button>
            </form>
        </x-card>
        @endcan
        <x-card :title="__('payments.title')"><div class="space-y-3">@foreach($this->methods as $method)<div class="rounded-xl border p-4 dark:border-slate-700"><p class="font-black">{{ $method->display_name }} @if($method->is_default)· {{ __('payments.is_default') }}@endif</p><p class="text-sm text-slate-500">{{ __('payments.types.'.$method->type) }} · {{ $method->is_active ? __('application.active_a733b809') : __('application.inactive_09af574c') }}</p><p class="break-words text-sm">{{ $method->account_name }} {{ $method->account_number }}</p>@can('payment_methods.manage')<button wire:click="edit({{ $method->id }})" class="mt-2 font-bold text-build-orange">{{ __('payments.edit') }}</button>@endcan</div>@endforeach</div></x-card>
    </div>
</div>
