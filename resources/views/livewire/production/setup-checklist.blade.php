<?php
use App\Models\Machine;
use App\Models\StockLocation;
use App\Support\CompanyFeatures;
use function Livewire\Volt\{layout,mount,state};
layout('layouts.app');
state(['suggestions'=>[]]);
mount(function(){
    abort_unless(CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.view'),403);
    $this->suggestions=session('production_setup_suggestions',[]);
});
?>
<div>
    <x-page-header :title="__('setup.checklist.title')" :description="__('setup.checklist.description')" :breadcrumbs="['Dashboard'=>route('dashboard'),__('production.title')=>route('production.index'),__('setup.checklist.title')=>null]" />
    @php
        $locationCodes=['RAW-MATERIALS','PRODUCTION-AREA','CURING-YARD','FINISHED-GOODS'];
        $locations=StockLocation::query()->whereIn('code',$locationCodes)->where('is_active',true)->get();
        $machineCount=Machine::query()->forCurrentCompany()->count();
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach([
            [__('setup.checklist.enabled'),true,null,null],
            [__('setup.checklist.locations'),$locations->count()===4,null,$locations->pluck('name')->join(', ')],
            [__('setup.checklist.machines'),$machineCount>0,route('production.machines.index'),$machineCount.' configured'],
            [__('setup.checklist.products'),false,route('products.create'),null],
            [__('setup.checklist.recipes'),false,route('production.recipes.create'),null],
            [__('setup.checklist.schedule'),false,route('production.schedule.index'),null],
        ] as [$label,$complete,$route,$caption])
            <x-card><div class="flex items-start gap-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $complete?'bg-emerald-500':'bg-slate-200 dark:bg-slate-700' }} text-white">{{ $complete?'✓':'·' }}</span><div><h2 class="font-black">{{$label}}</h2>@if($caption)<p class="mt-1 text-xs text-slate-500">{{$caption}}</p>@endif @if($route)<a href="{{$route}}" wire:navigate class="mt-3 inline-flex rounded-lg border px-3 py-2 text-sm font-bold">Continue</a>@endif</div></div></x-card>
        @endforeach
    </div>
    @if($suggestions)
        <x-card class="mt-5"><h2 class="font-black">{{__('setup.curing_defaults')}}</h2><p class="mt-2 text-sm text-slate-500">{{$suggestions['default_sellable_after_days']??'—'}} / {{$suggestions['default_curing_days']??'—'}} {{__('setup.days')}} · {{__('setup.quality_control')}}: {{($suggestions['quality_control_preference']??false)?__('setup.yes'):__('setup.no')}}</p><p class="mt-2 text-xs text-slate-500">Product-level curing and quality settings remain authoritative.</p></x-card>
    @endif
    <div class="mt-5"><a href="{{route('dashboard')}}" wire:navigate class="inline-flex rounded-xl bg-build-orange px-5 py-3 font-black text-white">Go to Dashboard</a></div>
</div>
