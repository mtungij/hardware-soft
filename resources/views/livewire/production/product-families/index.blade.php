<?php

use App\Models\ProductFamily;
use App\Models\Unit;
use App\Support\CompanyFeatures;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'activeFilter' => '',
    'editingId' => null,
    'name' => '',
    'code' => '',
    'description' => '',
    'icon' => 'cube',
    'colour' => 'cyan',
    'active' => true,
    'production_method' => ProductFamily::METHOD_MACHINE_MOULD,
    'default_curing_days' => '',
    'default_earliest_release_days' => '',
    'default_requires_curing' => false,
    'default_requires_qc' => false,
    'default_selling_unit_id' => '',
    'default_inventory_unit_id' => '',
]);

mount(function (): void {
    abort_unless(
        CompanyFeatures::manufacturingEnabled()
        && collect(['production.view_product_families', 'production.manage_product_families'])
            ->contains(fn (string $permission) => auth()->user()?->can($permission)),
        403,
    );

    ProductFamily::ensureDefaultsForCompany((int) CompanyFeatures::companyId());
});

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'code' => [
        'required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        Rule::unique('product_families', 'code')
            ->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId()))
            ->ignore($this->editingId),
    ],
    'description' => ['nullable', 'string', 'max:2000'],
    'icon' => ['required', Rule::in(ProductFamily::ICONS)],
    'colour' => ['required', Rule::in(ProductFamily::COLOURS)],
    'active' => ['boolean'],
    'production_method' => ['required', Rule::in(ProductFamily::PRODUCTION_METHODS)],
    'default_requires_curing' => ['boolean'],
    'default_curing_days' => [$this->default_requires_curing ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
    'default_earliest_release_days' => [$this->default_requires_curing ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535', 'lte:default_curing_days'],
    'default_requires_qc' => ['boolean'],
    'default_selling_unit_id' => [
        'nullable',
        Rule::exists('units', 'id')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId())),
    ],
    'default_inventory_unit_id' => [
        'nullable',
        Rule::exists('units', 'id')->where(fn ($query) => $query->where('company_id', CompanyFeatures::companyId())),
    ],
]);

$canManage = fn (): bool => auth()->user()?->can('production.manage_product_families') ?? false;

$resetForm = function (): void {
    $this->reset([
        'editingId', 'name', 'code', 'description', 'default_curing_days',
        'default_earliest_release_days', 'default_requires_curing', 'default_requires_qc',
        'default_selling_unit_id', 'default_inventory_unit_id',
    ]);
    $this->icon = 'cube';
    $this->colour = 'cyan';
    $this->active = true;
    $this->production_method = ProductFamily::METHOD_MACHINE_MOULD;
    $this->resetValidation();
};

$save = function (): void {
    abort_unless($this->canManage(), 403);
    $validated = $this->validate();
    $validated['company_id'] = CompanyFeatures::companyId();
    $validated['default_curing_days'] = $validated['default_requires_curing'] ? $validated['default_curing_days'] : null;
    $validated['default_earliest_release_days'] = $validated['default_requires_curing'] ? $validated['default_earliest_release_days'] : null;
    $validated['default_selling_unit_id'] = filled($validated['default_selling_unit_id']) ? $validated['default_selling_unit_id'] : null;
    $validated['default_inventory_unit_id'] = filled($validated['default_inventory_unit_id']) ? $validated['default_inventory_unit_id'] : null;

    $family = $this->editingId
        ? ProductFamily::query()->forCurrentCompany()->findOrFail($this->editingId)
        : new ProductFamily();
    $family->fill($validated)->save();

    $this->resetForm();
    session()->flash('success', __('production.product_families.saved'));
};

$editFamily = function (int $familyId): void {
    abort_unless($this->canManage(), 403);
    $family = ProductFamily::query()->forCurrentCompany()->findOrFail($familyId);
    $this->editingId = $family->id;
    $this->name = $family->name;
    $this->code = $family->code;
    $this->description = $family->description ?? '';
    $this->icon = $family->icon ?: 'cube';
    $this->colour = $family->colour ?: 'cyan';
    $this->active = (bool) $family->active;
    $this->production_method = $family->production_method ?: ProductFamily::METHOD_MACHINE_MOULD;
    $this->default_curing_days = $family->default_curing_days ?? '';
    $this->default_earliest_release_days = $family->default_earliest_release_days ?? '';
    $this->default_requires_curing = (bool) $family->default_requires_curing;
    $this->default_requires_qc = (bool) $family->default_requires_qc;
    $this->default_selling_unit_id = $family->default_selling_unit_id ? (string) $family->default_selling_unit_id : '';
    $this->default_inventory_unit_id = $family->default_inventory_unit_id ? (string) $family->default_inventory_unit_id : '';
    $this->resetValidation();
};

$deleteFamily = function (int $familyId): void {
    abort_unless($this->canManage(), 403);
    $family = ProductFamily::query()->forCurrentCompany()->withCount('products')->findOrFail($familyId);

    if ($family->products_count > 0) {
        session()->flash('error', __('production.product_families.in_use'));

        return;
    }

    $family->delete();
    if ((int) $this->editingId === $familyId) {
        $this->resetForm();
    }
    session()->flash('success', __('production.product_families.deleted'));
};

$updatedDefaultRequiresCuring = function (): void {
    if (! $this->default_requires_curing) {
        $this->default_curing_days = '';
        $this->default_earliest_release_days = '';
    }
};

$families = function () {
    return ProductFamily::query()->forCurrentCompany()
        ->with(['defaultSellingUnit', 'defaultInventoryUnit'])
        ->withCount('products')
        ->when(filled($this->search), fn ($query) => $query->where(fn ($nested) => $nested
            ->where('name', 'like', '%'.$this->search.'%')
            ->orWhere('code', 'like', '%'.$this->search.'%')))
        ->when($this->activeFilter !== '', fn ($query) => $query->where('active', $this->activeFilter === '1'))
        ->orderByDesc('active')->orderBy('name')->paginate(12);
};

?>

<div>
    <x-page-header
        :title="__('production.product_families.title')"
        :description="__('production.product_families.description')"
        :breadcrumbs="[__('production.product_families.dashboard') => route('dashboard'), __('production.title') => route('production.index'), __('production.product_families.title') => null]"
    />

    @if (session('success'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">{{ session('error') }}</div>@endif

    <div class="grid items-start gap-6 {{ $this->canManage() ? 'xl:grid-cols-[minmax(320px,430px)_minmax(0,1fr)]' : '' }}">
        @if ($this->canManage())
            <x-card :title="$editingId ? __('production.product_families.edit') : __('production.product_families.create')">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input :label="__('production.product_families.name')" name="name" wire:model="name" required />
                    <x-form-input :label="__('production.product_families.code')" name="code" wire:model="code" placeholder="concrete-blocks" required />
                    <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.description_field') }}
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white"></textarea>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.icon') }}
                            <select wire:model="icon" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                                @foreach(ProductFamily::ICONS as $option)<option value="{{ $option }}">{{ __('production.product_families.icons.'.$option) }}</option>@endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.colour') }}
                            <select wire:model="colour" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                                @foreach(ProductFamily::COLOURS as $option)<option value="{{ $option }}">{{ __('production.product_families.colours.'.$option) }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <label class="flex items-center gap-3 text-sm font-bold text-slate-800 dark:text-slate-200"><input type="checkbox" wire:model="active" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">{{ __('production.product_families.active') }}</label>
                    <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">Production Method
                        <select data-testid="production-method" wire:model="production_method" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <option value="machine_mould">Machine + Mould</option>
                            <option value="mould_only">Mould Only</option>
                        </select>
                    </label>

                    <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60">
                        <legend class="px-1 text-sm font-black text-slate-950 dark:text-white">{{ __('production.product_families.defaults') }}</legend>
                        <label class="flex items-center gap-3 text-sm font-bold text-slate-800 dark:text-slate-200"><input type="checkbox" wire:model.live="default_requires_curing" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">{{ __('production.product_families.requires_curing') }}</label>
                        @if($default_requires_curing)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-form-input :label="__('production.product_families.earliest_release_days')" name="default_earliest_release_days" type="number" min="1" wire:model="default_earliest_release_days" required />
                                <x-form-input :label="__('production.product_families.curing_days')" name="default_curing_days" type="number" min="1" wire:model="default_curing_days" required />
                            </div>
                        @endif
                        <label class="flex items-center gap-3 text-sm font-bold text-slate-800 dark:text-slate-200"><input type="checkbox" wire:model="default_requires_qc" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">{{ __('production.product_families.requires_qc') }}</label>
                        @php
                            $units = Unit::query()->where('company_id', CompanyFeatures::companyId())
                                ->where('status', 'active')->orderBy('name')->get();
                        @endphp
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.inventory_unit') }}
                                <select wire:model="default_inventory_unit_id" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">{{ __('production.product_families.no_default') }}</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select>
                            </label>
                            <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.selling_unit') }}
                                <select wire:model="default_selling_unit_id" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">{{ __('production.product_families.no_default') }}</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select>
                            </label>
                        </div>
                    </fieldset>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="submit" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-cyan-700 active:bg-cyan-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 dark:bg-cyan-500 dark:text-slate-950 dark:hover:bg-cyan-400 dark:focus:ring-offset-slate-950">{{ $editingId ? __('production.product_families.update') : __('production.product_families.save') }}</button>
                        @if($editingId)<button type="button" wire:click="resetForm" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-900 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.product_families.cancel') }}</button>@endif
                    </div>
                </form>
            </x-card>
        @endif

        <div class="min-w-0 space-y-4">
            <div class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:grid-cols-[minmax(0,1fr)_200px]">
                <x-form-input :label="__('production.product_families.search')" name="search" wire:model.live.debounce.300ms="search" />
                <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.status') }}
                    <select wire:model.live="activeFilter" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">{{ __('production.product_families.all') }}</option><option value="1">{{ __('production.product_families.active') }}</option><option value="0">{{ __('production.product_families.inactive') }}</option></select>
                </label>
            </div>

            @php
                $familyPage = $this->families();
            @endphp
            <div class="grid gap-4 md:grid-cols-2">
                @forelse($familyPage as $family)
                    @php
                        $colourClass = match($family->colour) {
                            'cyan' => 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100',
                            'sky' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100',
                            'blue' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100',
                            'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100',
                            'violet' => 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-100',
                            'teal' => 'border-teal-200 bg-teal-50 text-teal-800 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-100',
                            'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
                            'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
                            'orange' => 'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-100',
                            'red' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100',
                            default => 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100',
                        };
                    @endphp
                    <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3"><span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border text-sm font-black {{ $colourClass }}" aria-hidden="true">{{ str($family->icon)->substr(0, 1)->upper() }}</span><div class="min-w-0"><h2 class="truncate font-black text-slate-950 dark:text-white">{{ $family->name }}</h2><p class="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $family->code }}</p></div></div>
                            <span class="rounded-full border px-2 py-1 text-[11px] font-bold {{ $family->active ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $family->active ? __('production.product_families.active') : __('production.product_families.inactive') }}</span>
                        </div>
                        @if($family->description)<p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ $family->description }}</p>@endif
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.product_families.curing') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $family->default_requires_curing ? __('production.product_families.days_pair', ['release' => $family->default_earliest_release_days, 'curing' => $family->default_curing_days]) : __('production.product_families.not_required') }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.product_families.qc') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $family->default_requires_qc ? __('production.product_families.required') : __('production.product_families.not_required') }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.product_families.inventory_unit') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $family->defaultInventoryUnit?->short_name ?: __('production.product_families.no_default') }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ __('production.product_families.products') }}</dt><dd class="font-bold text-slate-900 dark:text-white">{{ $family->products_count }}</dd></div>
                        </dl>
                        @if($this->canManage())<div class="mt-4 flex flex-wrap gap-2"><button type="button" wire:click="editFamily({{ $family->id }})" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-900 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.product_families.edit_action') }}</button><button type="button" wire:click="deleteFamily({{ $family->id }})" wire:confirm="{{ __('production.product_families.delete_confirm') }}" class="rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-black text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-red-500/40 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/10">{{ __('production.product_families.delete') }}</button></div>@endif
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 md:col-span-2">{{ __('production.product_families.empty') }}</div>
                @endforelse
            </div>
            <div>{{ $familyPage->links() }}</div>
        </div>
    </div>
</div>
