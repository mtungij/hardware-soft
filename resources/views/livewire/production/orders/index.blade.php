<?php

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app'); uses([WithPagination::class]);
state(['search' => '', 'statusFilter' => '', 'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'machineFilter' => '', 'productFilter' => '', 'dateFrom' => '', 'dateTo' => '']);
mount(fn () => abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_orders','production.create_orders','production.execute_orders','production.complete_orders','production.cancel_orders'])->contains(fn ($p) => auth()->user()?->can($p)), 403));
?>
<div>
    <x-page-header :title="__('production.orders.title')" description="{{ __('application.controlled_production_execution_and_one_time_stock_posting_fa4ce456') }}" :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.orders.title') => null]">
        @can('production.create_orders')<a href="{{ route('production.orders.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.create_from_schedule_5a9a42cd') }}</a>@endcan
    </x-page-header>
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" placeholder="{{ __('application.order_product_machine_0344b66e') }}" class="rounded-lg border-slate-200 md:col-span-2 dark:bg-navy-950">
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_statuses_6405179d') }}</option>@foreach (ProductionOrder::STATUSES as $status)<option value="{{ $status }}">{{ \App\Support\UiText::label($status) }}</option>@endforeach</select>
            <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="machineFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_machines_957bf628') }}</option>@foreach (Machine::query()->forCurrentCompany()->get() as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.all_products_3ffece7c') }}</option>@foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border-slate-200 dark:bg-navy-950"><input type="date" wire:model.live="dateTo" class="rounded-lg border-slate-200 dark:bg-navy-950">
        </div>
        @php
            $orders = ProductionOrder::query()->forCurrentCompany()->with(['machine','mould','product','branch'])
                ->when($search, fn($q) => $q->where(fn($x) => $x->where('order_number','like',"%{$search}%")->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))->orWhereHas('machine',fn($m)=>$m->where('name','like',"%{$search}%"))))
                ->when($statusFilter,fn($q)=>$q->where('status',$statusFilter))->when($branchFilter,fn($q)=>$q->where('branch_id',$branchFilter))
                ->when($machineFilter,fn($q)=>$q->where('machine_id',$machineFilter))->when($productFilter,fn($q)=>$q->where('product_id',$productFilter))
                ->when($dateFrom,fn($q)=>$q->whereDate('production_date','>=',$dateFrom))->when($dateTo,fn($q)=>$q->whereDate('production_date','<=',$dateTo))
                ->latest()->paginate(12);
        @endphp
        <x-table :headers="[__('application.order_1d75774c'),__('application.date_eb9a4bc1'),__('terminology.method_equipment_4273c192'),__('application.product_dd3b86d1'),__('application.planned_9cbe42aa'),__('application.accepted_61a0572c'),__('application.rejected_27eeb7a2'),__('application.status_bae7d5be'),__('application.branch_1627510b'),__('application.actions_c3cd636a')]">
            @forelse ($orders as $order)<tr><td class="px-4 py-3 font-black">{{ $order->order_number }}</td><td class="px-4 py-3">{{ $order->production_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td><td class="px-4 py-3"><span class="block font-bold">{{ $order->productionMethodLabel() }}</span><span class="text-xs text-slate-500">{{ __('application.machine_d522b43c') }} {{ $order->machine?->name ?: '—' }} {{ __('application.mould_49047445') }} {{ $order->mould?->name ?: '—' }}</span></td><td class="px-4 py-3">{{ $order->product?->name }}</td><td class="px-4 py-3">{{ $order->planned_quantity }}</td><td class="px-4 py-3">{{ $order->accepted_quantity }}</td><td class="px-4 py-3">{{ $order->rejected_quantity }}</td><td class="px-4 py-3"><span class="badge-warning">{{ \App\Support\UiText::label($order->status) }}</span></td><td class="px-4 py-3">{{ $order->branch?->name ?: '—' }}</td><td class="px-4 py-3"><a href="{{ route('production.orders.show',$order) }}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">{{ $order->status===ProductionOrder::STATUS_AWAITING_COMPLETION ? __('application.review_complete_803fcaf0') : __('application.view_69bd4ef9') }}</a></td></tr>
            @empty <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_production_orders_found_b2839e47') }}</td></tr>@endforelse
        </x-table><div class="mt-4">{{ $orders->links() }}</div>
    </x-card>
</div>
