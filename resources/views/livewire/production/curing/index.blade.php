<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductionCuringBatch;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '', 'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'productFilter' => '', 'statusFilter' => '', 'productionDate' => '', 'sellableDate' => '']);
mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled()
    && collect(['production.view_curing', 'production.manage_curing', 'production.release_curing'])->contains(fn ($permission) => auth()->user()?->can($permission)),
    403
));
?>
<div>
    <x-page-header :title="__('production.curing.title')" description="{{ __('application.real_non_sellable_curing_stock_awaiting_controlled_release_e1ed63c9') }}" :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.curing.title') => null]" />
    <x-card>
        <div class="mb-5 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <input wire:model.live.debounce.300ms="search" placeholder="{{ __('application.batch_order_product_machine_3e7b2642') }}" class="rounded-lg border-slate-200 md:col-span-2 dark:bg-navy-950">
            <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_products_3ffece7c') }}</option>@foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->where('requires_curing', true)->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_statuses_6405179d') }}</option><option value="curing">{{ __('application.still_curing_fdbb5a78') }}</option><option value="ready_for_release">{{ __('application.ready_for_release_1e3442bd') }}</option><option value="eligible">{{ __('application.eligible_today_288eff89') }}</option><option value="full">{{ __('application.overdue_fully_cured_f1535944') }}</option><option value="partially_released">{{ __('application.partially_released_5ce44843') }}</option><option value="released">{{ __('application.released_35d9bc51') }}</option><option value="quarantined">{{ __('application.quarantined_15f4df9f') }}</option></select>
            <input type="date" wire:model.live="productionDate" title="{{ __('application.production_date_be95fbdf') }}" class="rounded-lg border-slate-200 dark:bg-navy-950">
            <input type="date" wire:model.live="sellableDate" title="{{ __('application.earliest_sellable_date_1117d881') }}" class="rounded-lg border-slate-200 dark:bg-navy-950">
        </div>
        @php
            $now = now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'));
            $batches = ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo(auth()->user())->with(['product.unit','machine','productionOrder','branch','sourceLocation'])
                ->when($search, fn($q) => $q->where(fn($x) => $x->where('batch_number','like',"%{$search}%")->orWhereHas('productionOrder',fn($o)=>$o->where('order_number','like',"%{$search}%"))->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))->orWhereHas('machine',fn($m)=>$m->where('name','like',"%{$search}%"))))
                ->when($branchFilter,fn($q)=>$q->where('branch_id',$branchFilter))->when($productFilter,fn($q)=>$q->where('product_id',$productFilter))
                ->when($productionDate,fn($q)=>$q->whereDate('production_date',$productionDate))->when($sellableDate,fn($q)=>$q->whereDate('minimum_sellable_at',$sellableDate))
                ->when($statusFilter === 'curing',fn($q)=>$q->where('status','curing')->where('minimum_sellable_at','>',$now))
                ->when($statusFilter === 'eligible',fn($q)=>$q->whereNotIn('status',['quarantined','closed','released'])->where('released_quantity',0)->where('minimum_sellable_at','<=',$now))
                ->when($statusFilter === 'ready_for_release',fn($q)=>$q->where('status',ProductionCuringBatch::STATUS_READY_FOR_RELEASE))
                ->when($statusFilter === 'full',fn($q)=>$q->where('full_curing_at','<=',$now)->where('remaining_quantity','>',0))
                ->when(in_array($statusFilter,['partially_released','released','quarantined']),fn($q)=>$q->where('status',$statusFilter))
                ->orderBy('minimum_sellable_at')->paginate(15);
        @endphp
        <div class="hidden overflow-x-auto md:block">
            <x-table :headers="[__('application.batch_8bf1ef56'),__('terminology.product_order_2932c68a'),__('application.production_df70fc79'),__('terminology.age_ff9f1ff3'),__('terminology.earliest_sellable_f7e37cea'),__('application.full_curing_f59aa9ce'),__('application.accepted_61a0572c'),__('application.released_35d9bc51'),__('application.damaged_052fc798'),__('application.remaining_cc632b5e'),__('application.location_d219c681'),__('application.status_bae7d5be'),__('application.actions_c3cd636a')]">
                @forelse($batches as $batch)
                    <tr>
                        <td class="px-3 py-3 font-black">{{ $batch->batch_number }}</td><td class="px-3 py-3">{{ $batch->product?->name }}<br><span class="text-xs text-slate-500">{{ $batch->productionOrder?->order_number }}</span></td>
                        <td class="px-3 py-3">{{ $batch->production_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td><td class="px-3 py-3">{{ $batch->curing_started_at->diffInDays($now) }} {{ __('application.days_5548ae4f') }}</td>
                        <td class="px-3 py-3">{{ $batch->minimum_sellable_at->locale(app()->getLocale())->translatedFormat('d M Y') }}</td><td class="px-3 py-3">{{ $batch->full_curing_at->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                        @foreach(['accepted_quantity','released_quantity','damaged_quantity','remaining_quantity'] as $field)<td class="px-3 py-3">{{ number_format((float)$batch->{$field},4) }}</td>@endforeach
                        <td class="px-3 py-3">{{ $batch->sourceLocation?->name }}</td><td class="px-3 py-3"><span class="badge-warning">{{ str($batch->resolvedStatus())->headline() }}</span></td>
                        <td class="px-3 py-3"><a href="{{ route('production.curing.show',$batch) }}" wire:navigate class="rounded-lg border px-2 py-1 text-xs font-black">{{ __('application.view_69bd4ef9') }}</a></td>
                    </tr>
                @empty <tr><td colspan="13" class="px-4 py-10 text-center text-slate-500">{{ __('application.no_curing_batches_found_f1b66a51') }}</td></tr>@endforelse
            </x-table>
        </div>
        <div class="grid gap-3 md:hidden">
            @foreach($batches as $batch)<a href="{{ route('production.curing.show',$batch) }}" wire:navigate class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div class="flex justify-between gap-3"><p class="font-black">{{ $batch->batch_number }}</p><span class="text-xs font-bold">{{ str($batch->resolvedStatus())->headline() }}</span></div><p class="mt-1">{{ $batch->product?->name }}</p><p class="mt-2 text-xs text-slate-500">{{ __('application.sellable_23760f60') }} {{ $batch->minimum_sellable_at->locale(app()->getLocale())->translatedFormat('d M Y') }} {{ __('application.remaining_741c09be') }} {{ number_format((float)$batch->remaining_quantity,4) }}</p></a>@endforeach
        </div>
        <div class="mt-4">{{ $batches->links() }}</div>
    </x-card>
</div>
