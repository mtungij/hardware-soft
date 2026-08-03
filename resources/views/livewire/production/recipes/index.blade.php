<?php

use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Services\ProductionRecipeService;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'productFilter' => '']);

mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled()
    && (auth()->user()?->can('production.view_recipes') || auth()->user()?->can('production.manage_recipes')),
    403
));

$canManage = fn (): bool => auth()->user()?->can('production.manage_recipes') ?? false;

$activate = function (int $recipeId): void {
    abort_unless($this->canManage(), 403);
    $recipe = ProductionRecipe::query()->forCurrentCompany()->findOrFail($recipeId);
    app(ProductionRecipeService::class)->activate($recipe, auth()->user());
    session()->flash('success', 'Recipe activated. Any previously active version was deactivated.');
};

$deactivate = function (int $recipeId): void {
    abort_unless($this->canManage(), 403);
    $recipe = ProductionRecipe::query()->forCurrentCompany()->findOrFail($recipeId);
    app(ProductionRecipeService::class)->deactivate($recipe, auth()->user());
    session()->flash('success', 'Recipe deactivated.');
};

$duplicate = function (int $recipeId) {
    abort_unless($this->canManage(), 403);
    $recipe = ProductionRecipe::query()->forCurrentCompany()->findOrFail($recipeId);
    $copy = app(ProductionRecipeService::class)->duplicate($recipe, auth()->user());
    session()->flash('success', 'Draft recipe copy created.');

    return $this->redirectRoute('production.recipes.edit', $copy, navigate: true);
};

?>

<div>
    <x-page-header
        :title="__('production.recipes.title')"
        description="Versioned material formulas and informational direct production inputs."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.recipes.title') => null]"
    >
        @if ($this->canManage())
            <a href="{{ route('production.recipes.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Create Recipe</a>
        @endif
    </x-page-header>

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" placeholder="Search recipe, code, or product..." class="rounded-lg border-slate-200 md:col-span-2 dark:border-slate-700 dark:bg-navy-950">
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option><option value="draft">Draft</option><option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                <option value="">All manufactured products</option>
                @foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
            </select>
        </div>

        @php
            $recipes = ProductionRecipe::query()->forCurrentCompany()->with(['product', 'outputUnit'])->withCount('items')
                ->when($search, fn ($q) => $q->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
                ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
                ->when($productFilter, fn ($q) => $q->where('product_id', $productFilter))
                ->latest()->paginate(12);
        @endphp

        <div class="hidden md:block">
            <x-table :headers="['Recipe', 'Product', 'Version', 'Output Quantity', 'Status', 'Effective Date', 'Actions']">
                @forelse ($recipes as $recipe)
                    <tr>
                        <td class="px-4 py-3"><p class="font-black">{{ $recipe->name }}</p><p class="text-xs text-slate-500">{{ $recipe->code ?: 'No code' }} · {{ $recipe->items_count }} items</p></td>
                        <td class="px-4 py-3">{{ $recipe->product?->name }}</td>
                        <td class="px-4 py-3">{{ $recipe->version ?: '—' }}</td>
                        <td class="px-4 py-3">{{ rtrim(rtrim($recipe->output_quantity, '0'), '.') }} {{ $recipe->outputUnit?->short_name }}</td>
                        <td class="px-4 py-3"><span class="{{ $recipe->status === 'active' ? 'badge-success' : ($recipe->status === 'draft' ? 'badge-warning' : 'badge-danger') }}">{{ ucfirst($recipe->status) }}</span></td>
                        <td class="px-4 py-3">{{ $recipe->effective_from?->format('d M Y') ?: '—' }}</td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-1">
                            <a href="{{ route('production.recipes.show', $recipe) }}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">View</a>
                            @if ($this->canManage())
                                @if ($recipe->status !== 'active')<a href="{{ route('production.recipes.edit', $recipe) }}" wire:navigate class="erp-btn-secondary px-2 py-1 text-xs">Edit</a>@endif
                                <button wire:click="duplicate({{ $recipe->id }})" class="rounded border px-2 py-1 text-xs font-bold">Duplicate</button>
                                @if ($recipe->status === 'active')
                                    <button wire:click="deactivate({{ $recipe->id }})" class="rounded bg-slate-700 px-2 py-1 text-xs font-bold text-white">Deactivate</button>
                                @else
                                    <button wire:click="activate({{ $recipe->id }})" class="rounded bg-emerald-700 px-2 py-1 text-xs font-bold text-white">Activate</button>
                                @endif
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No recipes found.</td></tr>
                @endforelse
            </x-table>
        </div>

        <div class="space-y-3 md:hidden">
            @forelse ($recipes as $recipe)
                <article class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex justify-between gap-3"><div><h3 class="font-black">{{ $recipe->name }}</h3><p class="text-sm text-slate-500">{{ $recipe->product?->name }} · v{{ $recipe->version ?: '—' }}</p></div><span class="badge-warning">{{ ucfirst($recipe->status) }}</span></div>
                    <p class="mt-3 text-sm">Output: {{ rtrim(rtrim($recipe->output_quantity, '0'), '.') }} {{ $recipe->outputUnit?->short_name }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('production.recipes.show', $recipe) }}" wire:navigate class="erp-btn-secondary px-3 py-1 text-xs">View details</a>
                        @if ($this->canManage() && $recipe->status !== 'active')
                            <a href="{{ route('production.recipes.edit', $recipe) }}" wire:navigate class="erp-btn-secondary px-3 py-1 text-xs">Edit</a>
                        @endif
                    </div>
                </article>
            @empty
                <p class="py-8 text-center text-slate-500">No recipes found.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $recipes->links() }}</div>
    </x-card>
</div>
