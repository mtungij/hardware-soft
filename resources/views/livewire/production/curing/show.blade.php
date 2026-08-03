<?php

use App\Models\ProductionCuringBatch;
use App\Models\StockLocation;
use App\Services\ProductionCuringService;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['batch' => null, 'release_quantity' => '', 'destination_location_id' => '', 'release_notes' => '', 'damage_quantity' => '', 'reason' => '', 'release_token' => '', 'action_token' => '']);

mount(function (ProductionCuringBatch $batch): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_curing','production.manage_curing','production.release_curing'])->contains(fn($p)=>auth()->user()?->can($p)), 403);
    $this->batch = $batch->load(['company','product.unit','machine','productionOrder.completer','sourceLocation','defaultReleaseLocation','releases.releaser','releases.destinationLocation','actions.creator','qualityInspections.inspector','qualityInspections.retests','qualityHolds.placer','qualityHolds.releaser']);
    $this->destination_location_id = (string) $batch->default_release_stock_location_id;
    $this->release_token = (string) str()->uuid();
    $this->action_token = (string) str()->uuid();
});

$refreshBatch = function (): void {
    $this->batch = $this->batch->refresh()->load(['company','product.unit','machine','productionOrder.completer','sourceLocation','defaultReleaseLocation','releases.releaser','releases.destinationLocation','actions.creator','qualityInspections.inspector','qualityInspections.retests','qualityHolds.placer','qualityHolds.releaser']);
};
$release = function (): void {
    app(ProductionCuringService::class)->release($this->batch, $this->release_quantity, (int)$this->destination_location_id, auth()->user(), $this->release_token, $this->release_notes ?: null);
    $this->release_quantity = $this->release_notes = ''; $this->release_token = (string)str()->uuid(); $this->refreshBatch(); session()->flash('success',__('production.curing.details.release_success'));
};
$quarantine = function (): void {
    app(ProductionCuringService::class)->quarantine($this->batch,$this->reason,auth()->user(),$this->action_token);
    $this->reason=''; $this->action_token=(string)str()->uuid(); $this->refreshBatch();
};
$removeQuarantine = function (): void {
    app(ProductionCuringService::class)->removeQuarantine($this->batch,$this->reason,auth()->user(),$this->action_token);
    $this->reason=''; $this->action_token=(string)str()->uuid(); $this->refreshBatch();
};
$recordDamage = function (): void {
    app(ProductionCuringService::class)->recordDamage($this->batch,$this->damage_quantity,$this->reason,auth()->user(),$this->action_token);
    $this->damage_quantity=$this->reason=''; $this->action_token=(string)str()->uuid(); $this->refreshBatch(); session()->flash('success',__('production.curing.details.damage_success'));
};
?>
<div>
    <x-page-header :title="$batch->batch_number" :description="__('production.curing.details.page_description')" :breadcrumbs="[__('production.curing.details.dashboard')=>route('dashboard'),__('production.curing.title')=>route('production.curing.index'),$batch->batch_number=>null]" />
    @if(session('success'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif
    @php
        $t = fn (string $key, array $replace = []) => __('production.curing.details.'.$key, $replace);
        $timezone = $batch->company?->timezone ?: config('app.timezone');
        $now = now($timezone);
        $startedAt = $batch->curing_started_at->copy()->timezone($timezone);
        $earliestAt = $batch->minimum_sellable_at->copy()->timezone($timezone);
        $fullAt = $batch->full_curing_at->copy()->timezone($timezone);
        $totalSeconds = max(1, $fullAt->timestamp - $startedAt->timestamp);
        $elapsedSeconds = $now->timestamp - $startedAt->timestamp;
        $clampedSeconds = max(0, min($totalSeconds, $elapsedSeconds));
        $progress = max(0, min(100, (int) round(($clampedSeconds * 100) / $totalSeconds)));
        $totalCuringDays = max(1, (int) ceil($totalSeconds / 86400));
        $completedCuringDays = min($totalCuringDays, max(0, intdiv(max(0, $elapsedSeconds), 86400)));
        $progressSummary = $completedCuringDays < 1
            ? $t('less_than_day_progress', ['total' => $totalCuringDays])
            : $t('days_progress', ['current' => $completedCuringDays, 'total' => $totalCuringDays]);
        $ageDays = max(0, intdiv(max(0, $elapsedSeconds), 86400));
        $currentAge = $elapsedSeconds < 86400 ? $t('less_than_one_day') : ($ageDays === 1 ? $t('day') : $t('days', ['count' => $ageDays]));
        $releaseSeconds = max(0, $earliestAt->timestamp - $now->timestamp);
        $releaseDays = $releaseSeconds > 0 ? (int) ceil($releaseSeconds / 86400) : 0;
        $timeUntilRelease = $releaseSeconds > 0 && $releaseSeconds < 86400
            ? $t('less_than_one_day')
            : ($releaseDays === 1 ? $t('day') : $t('days', ['count' => $releaseDays]));
        $resolvedStatus = $batch->resolvedStatus($now);
        $activeHold = $batch->qualityHolds->firstWhere('status', 'active');
        $approvedInspection = $batch->qualityInspections->first(function ($inspection): bool {
            return $inspection->inspection_stage === 'pre_release'
                && in_array($inspection->result, ['passed', 'conditional'], true)
                && $inspection->approval_status === 'approved'
                && ! $inspection->retests->contains(fn ($retest) => $retest->result === 'failed' || $retest->approval_status === 'rejected');
        });
        $fullyReleased = bccomp((string) $batch->remaining_quantity, '0', 12) <= 0 || $batch->status === ProductionCuringBatch::STATUS_RELEASED;
        $finalRelease = $fullyReleased ? $batch->releases->sortByDesc('released_at')->first() : null;
        $releaseBlocker = null;
        if ($batch->status === ProductionCuringBatch::STATUS_QUARANTINED) {
            $releaseBlocker = $t('quarantine_block', ['reason' => $batch->quarantine_reason ?: $t('quarantined')]);
        } elseif ($activeHold) {
            $releaseBlocker = $t('quality_hold_block', ['reason' => $activeHold->reason]);
        } elseif ($batch->product?->requires_pre_release_inspection && ! $approvedInspection) {
            $releaseBlocker = $t('pre_release_block');
        } elseif (bccomp((string) $batch->remaining_quantity, '0', 12) <= 0) {
            $releaseBlocker = $t('completed_block');
        } elseif (! $batch->isEligibleForRelease($now)) {
            $releaseBlocker = $t('date_block', ['date' => $earliestAt->format('d M Y')]);
        }
        $releaseReady = $releaseBlocker === null && $batch->isEligibleForRelease($now);
        $currentFlowStage = $fullyReleased
            ? 5
            : ($releaseReady
                ? 3
                : (($activeHold || $batch->status === ProductionCuringBatch::STATUS_QUARANTINED || ($batch->product?->requires_pre_release_inspection && ! $approvedInspection)) ? 2 : 1));
        $flowStages = [
            $t('production_completed'),
            $t('curing'),
            $t('quality_control'),
            $t('ready_for_release'),
            $t('finished_goods_store'),
        ];
        if ($fullyReleased) {
            $currentFlowStage = count($flowStages);
        }
        $statusLabels = [
            ProductionCuringBatch::STATUS_CURING => $t('curing'), ProductionCuringBatch::STATUS_ELIGIBLE => $t('eligible_for_release'),
            ProductionCuringBatch::STATUS_READY_FOR_RELEASE => $t('ready_for_release'),
            ProductionCuringBatch::STATUS_PARTIALLY_RELEASED => $t('partially_released'), ProductionCuringBatch::STATUS_RELEASED => $t('fully_released'),
            ProductionCuringBatch::STATUS_QUARANTINED => $t('quarantined'), ProductionCuringBatch::STATUS_CLOSED => $t('closed'),
        ];
        $statusClasses = [
            ProductionCuringBatch::STATUS_CURING => 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200',
            ProductionCuringBatch::STATUS_ELIGIBLE => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
            ProductionCuringBatch::STATUS_READY_FOR_RELEASE => 'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-400/40 dark:bg-emerald-400/15 dark:text-emerald-100',
            ProductionCuringBatch::STATUS_PARTIALLY_RELEASED => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            ProductionCuringBatch::STATUS_RELEASED => 'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-400/40 dark:bg-emerald-400/15 dark:text-emerald-100',
            ProductionCuringBatch::STATUS_QUARANTINED => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
            ProductionCuringBatch::STATUS_CLOSED => 'border-slate-300 bg-slate-100 text-slate-800 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200',
        ];
        $formatQuantity = function ($value): string {
            $decimal = rtrim(rtrim((string) $value, '0'), '.');
            return $decimal === '' ? '0' : $decimal;
        };
        $unit = $batch->product?->unit?->short_name;
        $destinations = StockLocation::query()->where('company_id',$batch->company_id)->where('branch_id',$batch->branch_id)->sellable()->where('is_active',true)->where('can_receive_stock',true)->orderBy('name')->get();
        $events = collect([
            [
                'group'=>10, 'at'=>$batch->productionOrder?->completed_at ?: $batch->created_at,
                'title'=>$t('production_completed'), 'detail'=>$batch->productionOrder?->posting_reference,
                'actual'=>true, 'tone'=>'green', 'badge'=>$t('posted_event'), 'badge_tone'=>'green',
                'actor'=>$batch->productionOrder?->completer?->name,
            ],
            [
                'group'=>20, 'at'=>$batch->curing_started_at, 'title'=>$t('curing_started'),
                'detail'=>$batch->sourceLocation?->name, 'actual'=>true, 'tone'=>'cyan',
                'badge'=>$t('posted_event'), 'badge_tone'=>'cyan', 'actor'=>null,
            ],
        ]);
        foreach ($batch->qualityInspections as $inspection) {
            $events->push([
                'group'=>30, 'at'=>$inspection->inspected_at ?: $inspection->created_at,
                'title'=>$t('quality_inspection'), 'detail'=>$inspection->inspection_number,
                'actual'=>true, 'tone'=>'purple', 'badge'=>$t('quality_event'),
                'badge_tone'=>'purple', 'actor'=>$inspection->inspector?->name,
            ]);
        }
        foreach ($batch->qualityHolds as $hold) {
            $events->push([
                'group'=>30, 'at'=>$hold->placed_at ?: $hold->created_at,
                'title'=>$t('quality_hold_created'), 'detail'=>$hold->reason, 'actual'=>true,
                'tone'=>'red', 'badge'=>$t('quality_hold'), 'badge_tone'=>'red', 'actor'=>$hold->placer?->name,
            ]);
            if ($hold->released_at) {
                $events->push([
                    'group'=>30, 'at'=>$hold->released_at, 'title'=>$t('quality_hold_released'),
                    'detail'=>$hold->release_reason, 'actual'=>true, 'tone'=>'teal',
                    'badge'=>$t('hold_released'), 'badge_tone'=>'teal', 'actor'=>$hold->releaser?->name,
                ]);
            }
        }
        $actionPresentation = [
            'quarantine'=>[$t('quarantined'), 'amber', $t('stock_hold'), 'amber', 40],
            'unquarantine'=>[$t('unquarantined'), 'teal', $t('hold_released'), 'teal', 40],
            'damage'=>[$t('damage_recorded'), 'red', $t('loss_event'), 'red', 40],
            'close'=>[$t('closed'), 'green', $t('completed'), 'green', 80],
        ];
        foreach ($batch->actions as $action) {
            [$title, $tone, $badge, $badgeTone, $group] = $actionPresentation[$action->action_type] ?? $actionPresentation['close'];
            $detail = collect([$action->quantity ? $formatQuantity($action->quantity).' '.$unit : null, $action->reason, $action->posting_reference])->filter()->implode(' · ');
            $events->push([
                'group'=>$group, 'at'=>$action->created_at, 'title'=>$title, 'detail'=>$detail,
                'actual'=>true, 'tone'=>$tone, 'badge'=>$badge, 'badge_tone'=>$badgeTone,
                'actor'=>$action->creator?->name,
            ]);
        }
        if (! $fullyReleased) {
            $events->push([
                'group'=>50, 'at'=>$batch->minimum_sellable_at, 'title'=>$t('earliest_release'),
                'detail'=>null, 'actual'=>false, 'tone'=>'violet', 'badge'=>$t('upcoming_milestone'),
                'badge_tone'=>'violet', 'actor'=>null,
            ]);
        }
        $lastReleaseId = $batch->releases->sortByDesc('released_at')->first()?->id;
        foreach ($batch->releases as $release) {
            $fullRelease = $release->id === $lastReleaseId && bccomp((string) $batch->remaining_quantity, '0', 12) <= 0;
            $events->push([
                'group'=>$fullRelease ? 80 : 60, 'at'=>$release->released_at,
                'title'=>$fullRelease ? $t('released_to_finished_goods') : $t('partial_release'),
                'detail'=>$formatQuantity($release->released_quantity).' '.$unit.' · '.$release->destinationLocation?->name.' · '.$release->posting_reference,
                'actual'=>true, 'tone'=>$fullRelease ? 'green' : 'teal',
                'badge'=>$fullRelease ? $t('completed') : $t('release_event'),
                'badge_tone'=>$fullRelease ? 'green' : 'teal', 'actor'=>$release->releaser?->name,
            ]);
        }
        if (! $fullyReleased) {
            $events->push([
                'group'=>70, 'at'=>$batch->full_curing_at, 'title'=>$t('full_curing'),
                'detail'=>null, 'actual'=>false, 'tone'=>'violet', 'badge'=>$t('upcoming_milestone'),
                'badge_tone'=>'violet', 'actor'=>null,
            ]);
        }
        $events = $events->sort(function (array $left, array $right): int {
            return [$left['group'], $left['at']?->timestamp ?? PHP_INT_MAX]
                <=> [$right['group'], $right['at']?->timestamp ?? PHP_INT_MAX];
        })->values();
    @endphp

    <div class="grid gap-5 xl:grid-cols-2">
        <x-card :title="$t('batch_summary')">
            <dl class="grid gap-4 sm:grid-cols-2">
                @foreach([
                    $t('batch_number')=>$batch->batch_number, $t('production_order')=>$batch->productionOrder?->order_number,
                    $t('product')=>$batch->product?->name, $t('machine')=>$batch->machine?->name,
                    $t('production_date')=>$batch->production_date->format('d M Y'),
                    $t('curing_location')=>$batch->sourceLocation?->name, $t('release_location')=>$batch->defaultReleaseLocation?->name,
                ] as $label=>$value)<div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 font-black text-slate-950 dark:text-white">{{ $value ?: '—' }}</dd></div>@endforeach
            </dl>
        </x-card>

        <x-card :title="$t('curing_progress')">
            <div class="flex items-end justify-between gap-4"><p class="text-4xl font-black text-cyan-700 dark:text-cyan-300">{{ $progress }}%</p><span class="inline-flex rounded-full border px-3 py-1 text-xs font-black {{ $statusClasses[$resolvedStatus] ?? $statusClasses[ProductionCuringBatch::STATUS_CURING] }}">{{ $statusLabels[$resolvedStatus] ?? str($resolvedStatus)->headline() }}</span></div>
            @if($activeHold)<div class="mt-3 inline-flex rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-black text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">{{ $t('quality_hold') }}</div>@endif
            <div class="mt-4 h-3 overflow-hidden rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800" role="progressbar" aria-label="{{ $t('curing_progress') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
                <div class="h-full rounded-full bg-cyan-500 transition-all dark:bg-cyan-400" style="width: {{ $progress }}%"></div>
            </div>
            <p class="mt-2 text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $progressSummary }}</p>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach([
                    $t('started')=>[$startedAt->format('d M Y'),$startedAt->format('d M Y H:i T')],
                    $t('current_age')=>[$currentAge,$startedAt->format('d M Y H:i T')],
                    $t('earliest_release')=>[$earliestAt->format('d M Y'),$earliestAt->format('d M Y H:i T')],
                    $t('days_remaining')=>[$timeUntilRelease,$earliestAt->format('d M Y H:i T')],
                    $t('full_curing')=>[$fullAt->format('d M Y'),$fullAt->format('d M Y H:i T')],
                    $t('status')=>[$statusLabels[$resolvedStatus] ?? str($resolvedStatus)->headline(),null],
                ] as $label=>$data)<div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 font-black text-slate-950 dark:text-white" @if($data[1]) title="{{ $data[1] }}" @endif>{{ $data[0] }}</dd></div>@endforeach
            </dl>
        </x-card>
    </div>

    <section class="mt-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" aria-labelledby="batch-flow-title">
        <h2 id="batch-flow-title" class="text-base font-black text-slate-950 dark:text-white">{{ $t('batch_flow') }}</h2>
        <ol class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach($flowStages as $index => $stage)
                @php
                    $stageState = $index < $currentFlowStage ? 'completed' : ($index === $currentFlowStage ? 'current' : 'pending');
                    $stageClass = match($stageState) {
                        'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
                        'current' => 'border-cyan-500 bg-cyan-50 text-cyan-950 ring-2 ring-cyan-500/30 dark:border-cyan-400 dark:bg-cyan-500/15 dark:text-cyan-100',
                        default => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
                    };
                @endphp
                <li class="relative min-w-0 rounded-xl border p-3 {{ $stageClass }}" @if($stageState === 'current') aria-current="step" @endif>
                    <p class="break-words text-sm font-black">{{ $stage }}</p>
                    <p class="mt-1 text-xs font-bold uppercase tracking-wide">{{ $t($stageState) }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            [$t('accepted'),'accepted_quantity',$t('accepted_help'),'border-cyan-200 bg-cyan-50 dark:border-cyan-500/30 dark:bg-cyan-500/10','text-cyan-600 dark:text-cyan-300'],
            [$t('released'),'released_quantity',$t('released_help'),'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10','text-emerald-600 dark:text-emerald-300'],
            [$t('damaged'),'damaged_quantity',$t('damaged_help'),'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10','text-red-600 dark:text-red-300'],
            [$t('remaining_curing'),'remaining_quantity',$t('remaining_help'),'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10','text-amber-600 dark:text-amber-300'],
        ] as [$label,$field,$help,$classes,$iconClasses])
            <section class="rounded-xl border p-4 shadow-sm {{ $classes }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ $label }}</h2>
                    <svg class="h-5 w-5 {{ $iconClasses }}" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p class="mt-2 text-2xl font-black text-slate-950 dark:text-white">{{ $formatQuantity($batch->{$field}) }} <span class="text-base">{{ $unit }}</span></p>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ $help }}</p>
            </section>
        @endforeach
    </div>

    <div class="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
        <x-card :title="$t('history')">
            <ol class="relative ml-2 border-l border-slate-200 dark:border-slate-700">
                @foreach($events as $event)
                    @php
                        $dotClass = match($event['tone']) {
                            'green'=>'border-emerald-500 bg-emerald-100 dark:bg-emerald-950', 'red'=>'border-red-500 bg-red-100 dark:bg-red-950',
                            'amber'=>'border-amber-500 bg-amber-100 dark:bg-amber-950', 'violet'=>'border-violet-500 bg-violet-100 dark:bg-violet-950',
                            'purple'=>'border-purple-500 bg-purple-100 dark:bg-purple-950', 'teal'=>'border-teal-500 bg-teal-100 dark:bg-teal-950',
                            'cyan'=>'border-cyan-500 bg-cyan-100 dark:bg-cyan-950', default=>'border-slate-400 bg-white dark:bg-slate-900',
                        };
                        $badgeClass = match($event['badge_tone']) {
                            'green'=>'border-emerald-200 bg-emerald-100 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/20 dark:text-emerald-100',
                            'cyan'=>'border-cyan-200 bg-cyan-100 text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/20 dark:text-cyan-100',
                            'purple'=>'border-purple-200 bg-purple-100 text-purple-800 dark:border-purple-500/30 dark:bg-purple-500/20 dark:text-purple-100',
                            'red'=>'border-red-200 bg-red-100 text-red-800 dark:border-red-500/30 dark:bg-red-500/20 dark:text-red-100',
                            'amber'=>'border-amber-200 bg-amber-100 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/20 dark:text-amber-100',
                            'teal'=>'border-teal-200 bg-teal-100 text-teal-800 dark:border-teal-500/30 dark:bg-teal-500/20 dark:text-teal-100',
                            default=>'border-violet-300 bg-transparent text-violet-800 dark:border-violet-400/50 dark:text-violet-200',
                        };
                    @endphp
                    <li class="relative mb-6 ml-6 min-w-0 last:mb-0">
                        <span class="absolute -left-[2.05rem] top-1 h-4 w-4 rounded-full border-2 {{ $dotClass }}" aria-hidden="true"></span>
                        <article class="min-w-0 rounded-xl border p-4 {{ $event['actual'] ? 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' : 'border-dashed border-violet-300 bg-violet-50 dark:border-violet-500/40 dark:bg-violet-500/10' }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h3 class="font-black text-slate-950 dark:text-white">{{ $event['title'] }}</h3>
                                <span class="rounded-full border px-2 py-1 text-[11px] font-bold {{ $badgeClass }}">{{ $event['badge'] }}</span>
                            </div>
                            <time class="mt-1 block text-sm font-semibold text-slate-600 dark:text-slate-300" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->copy()->timezone($timezone)->format('d M Y H:i') }}</time>
                            @if($event['actor'])<p class="mt-1 text-xs font-semibold text-slate-700 dark:text-slate-300">{{ $t('by_actor', ['name' => $event['actor']]) }}</p>@endif
                            @if($event['detail'])<p class="mt-2 break-words text-sm text-slate-600 dark:text-slate-400">{{ $event['detail'] }}</p>@endif
                        </article>
                    </li>
                @endforeach
            </ol>
        </x-card>

        <div class="space-y-5">
            @can('production.release_curing')
                <x-card :title="$t('release_to_finished_goods')">
                    @if($fullyReleased)
                        <section data-testid="fully-released-summary" class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-400/40 dark:bg-emerald-500/10 dark:text-emerald-100" aria-live="polite">
                            <span class="inline-flex rounded-full border border-emerald-300 bg-emerald-600 px-3 py-1 text-xs font-black uppercase tracking-wide text-white dark:border-emerald-400 dark:bg-emerald-400 dark:text-slate-950">{{ $t('fully_released') }}</span>
                            <h2 class="mt-3 text-lg font-black">{{ $t('released_to_finished_goods') }}</h2>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-1">
                                <div><dt class="font-bold text-emerald-700 dark:text-emerald-300">{{ $t('released_quantity') }}</dt><dd class="font-black">{{ $formatQuantity($batch->released_quantity) }} {{ $unit }}</dd></div>
                                <div><dt class="font-bold text-emerald-700 dark:text-emerald-300">{{ $t('released_by') }}</dt><dd class="font-black">{{ $finalRelease?->releaser?->name ?: '—' }}</dd></div>
                                <div><dt class="font-bold text-emerald-700 dark:text-emerald-300">{{ $t('released_at') }}</dt><dd class="font-black">{{ $finalRelease?->released_at?->copy()->timezone($timezone)->format('d M Y H:i') ?: '—' }}</dd></div>
                                <div><dt class="font-bold text-emerald-700 dark:text-emerald-300">{{ $t('destination') }}</dt><dd class="font-black">{{ $finalRelease?->destinationLocation?->name ?: '—' }}</dd></div>
                                <div class="sm:col-span-2 xl:col-span-1"><dt class="font-bold text-emerald-700 dark:text-emerald-300">{{ $t('posting_reference') }}</dt><dd class="break-words font-black">{{ $finalRelease?->posting_reference ?: '—' }}</dd></div>
                            </dl>
                        </section>
                    @else
                    <section class="mb-4 rounded-xl border p-4 {{ $releaseReady ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10' : 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' }}" aria-live="polite">
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-black uppercase tracking-wide {{ $releaseReady ? 'border-emerald-300 bg-emerald-600 text-white dark:border-emerald-400 dark:bg-emerald-400 dark:text-slate-950' : 'border-red-300 bg-red-600 text-white dark:border-red-400 dark:bg-red-500 dark:text-white' }}">{{ $releaseReady ? $t('ready_for_release') : $t('release_locked') }}</span>
                        <p class="mt-3 text-xs font-bold uppercase tracking-wide {{ $releaseReady ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">{{ $t('status') }}</p>
                        <h2 class="mt-1 text-lg font-black {{ $releaseReady ? 'text-emerald-900 dark:text-emerald-100' : 'text-amber-900 dark:text-amber-100' }}">{{ $releaseReady ? $t('ready_for_release') : $t('not_yet_eligible') }}</h2>
                        @if($releaseReady)<p class="mt-2 text-sm text-emerald-800 dark:text-emerald-200">{{ $t('ready_for_release_help') }}</p>@else<div class="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-1"><div><p class="font-bold text-amber-700 dark:text-amber-300">{{ $t('release_available_on') }}</p><p class="text-amber-950 dark:text-amber-100">{{ $earliestAt->format('d M Y') }}</p></div><div><p class="font-bold text-amber-700 dark:text-amber-300">{{ $t('time_remaining') }}</p><p class="text-amber-950 dark:text-amber-100">{{ $timeUntilRelease }}</p></div><div><p class="font-bold text-amber-700 dark:text-amber-300">{{ $t('full_curing') }}</p><p class="text-amber-950 dark:text-amber-100">{{ $fullAt->format('d M Y') }}</p></div><div class="sm:col-span-2 xl:col-span-1"><p class="font-bold text-red-700 dark:text-red-300">{{ $t('blocked_reason') }}</p><p class="text-red-900 dark:text-red-200">{{ $releaseBlocker }}</p></div></div>@endif
                    </section>
                    <form wire:submit="release" class="space-y-3">
                        <x-form-input :label="$t('quantity')" name="release_quantity" type="number" min="0.0001" step="0.0001" wire:model="release_quantity" :disabled="! $releaseReady" class="disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-500 dark:disabled:border-slate-700 dark:disabled:bg-slate-800 dark:disabled:text-slate-400" required />
                        <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ $t('destination') }}
                            <select wire:model="destination_location_id" @disabled(! $releaseReady) class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 bg-white text-slate-900 focus:border-emerald-500 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:disabled:bg-slate-800 dark:disabled:text-slate-400">@foreach($destinations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                        </label>
                        <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ $t('release_notes') }}
                            <textarea wire:model="release_notes" @disabled(! $releaseReady) class="mt-1 block min-h-24 w-full rounded-lg border border-slate-300 bg-white text-slate-900 focus:border-emerald-500 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:disabled:bg-slate-800 dark:disabled:text-slate-400"></textarea>
                        </label>
                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-700 bg-emerald-600 px-4 py-2.5 font-black text-white shadow-sm transition hover:bg-emerald-700 active:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-200 disabled:text-slate-600 disabled:opacity-100 dark:border-emerald-400 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400 dark:active:bg-emerald-300 dark:focus:ring-offset-slate-950 dark:disabled:border-slate-700 dark:disabled:bg-slate-800 dark:disabled:text-slate-400" @disabled(!$releaseReady) aria-describedby="release-help">{{ $t('release_stock') }}</button>
                        <p id="release-help" class="text-xs font-semibold text-slate-600 dark:text-slate-400">{{ $releaseReady ? $t('ready_for_release_help') : $releaseBlocker }}</p>
                    </form>
                    @endif
                </x-card>
            @endcan
            @can('production.manage_curing')
                <x-card :title="$t('quality_controls')"><div class="space-y-3"><x-form-input :label="$t('damage_quantity')" name="damage_quantity" type="number" min="0.0001" step="0.0001" wire:model="damage_quantity" /><label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ $t('required_reason') }}<textarea wire:model="reason" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-300 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white"></textarea></label><button wire:click="recordDamage" type="button" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-red-600 px-4 py-2.5 font-black text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:bg-red-500 dark:text-slate-950 dark:hover:bg-red-400 dark:focus:ring-offset-slate-950">{{ $t('record_damage') }}</button>@if($batch->status==='quarantined')<button wire:click="removeQuarantine" type="button" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-black text-slate-900 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800 dark:focus:ring-offset-slate-950">{{ $t('remove_quarantine') }}</button>@else<button wire:click="quarantine" type="button" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-amber-600 bg-amber-500 px-4 py-2.5 font-black text-slate-950 hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:border-amber-400 dark:bg-amber-400 dark:text-slate-950 dark:hover:bg-amber-300 dark:focus:ring-offset-slate-950">{{ $t('quarantine_batch') }}</button>@endif</div></x-card>
            @endcan
        </div>
    </div>
</div>
