<?php

use App\Models\ProductionQualityInspection;
use App\Models\User;
use App\Services\ProductionQualityService;
use App\Support\CompanyFeatures;
use Livewire\WithFileUploads;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithFileUploads::class]);

state([
    'inspection', 'decision_reason' => '', 'inspection_result' => 'passed',
    'accepted_quantity' => '', 'rejected_quantity' => '0', 'inspector_id' => '',
    'inspection_notes' => '', 'reason_justification' => '', 'corrective_action' => '',
    'retest_required' => false, 'retest_date' => '', 'disposition' => '',
    'check_answers' => [], 'evidence' => null, 'evidence_category' => 'product_photo',
]);

$reload = function (): void {
    $this->inspection = $this->inspection->fresh([
        'product.unit', 'plan', 'recipeSnapshot', 'results.unit', 'productionOrder', 'curingBatch',
        'machine', 'inspector', 'approver', 'holds.placer', 'holds.releaser', 'supersedes', 'retests',
        'attachments.uploader', 'auditEvents.user',
    ]);
};

mount(function (ProductionQualityInspection $inspection): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && auth()->user()?->canAny([
        'production.view_quality', 'production.perform_quality_inspections', 'production.record_qc_result',
        'production.approve_quality', 'production.approve_qc',
    ]), 403);
    $this->inspection = $inspection;
    $this->reload();
    $this->accepted_quantity = (string) ($this->inspection->curingBatch?->remaining_quantity ?? $this->inspection->applicable_quantity);
    $this->inspector_id = (string) ($this->inspection->inspected_by ?: auth()->id());
    $this->inspection_notes = $this->inspection->notes ?: '';
    foreach ($this->inspection->results as $line) {
        $this->check_answers[$line->id] = [
            'numeric_value' => $line->numeric_value,
            'boolean_value' => $line->boolean_value === null ? '' : (string) (int) $line->boolean_value,
            'text_value' => $line->text_value,
            'selected_value' => $line->selected_value,
            'manual_result' => in_array($line->result, ['passed', 'failed'], true) ? $line->result : '',
            'inspector_comment' => $line->inspector_comment,
        ];
    }
});

$recordInspection = function (): void {
    app(ProductionQualityService::class)->recordQueuedInspection($this->inspection, [
        'result' => $this->inspection_result,
        'accepted_quantity' => $this->accepted_quantity,
        'rejected_quantity' => $this->rejected_quantity,
        'inspector_id' => $this->inspector_id,
        'notes' => $this->inspection_notes,
        'reason_justification' => $this->reason_justification,
        'corrective_action' => $this->corrective_action,
        'retest_required' => $this->retest_required,
        'retest_date' => $this->retest_date,
        'disposition' => $this->disposition,
        'check_answers' => $this->check_answers,
    ], auth()->user());
    $this->reload();
    session()->flash('success', __('application.quality_inspection_recorded_and_is_ready_for_approval_556adb86'));
};

$uploadEvidence = function (): void {
    $this->validate([
        'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        'evidence_category' => ['required', 'in:product_photo,damage_photo,test_result,laboratory_certificate,other'],
    ]);
    app(ProductionQualityService::class)->addAttachment($this->inspection, $this->evidence, $this->evidence_category, auth()->user());
    $this->evidence = null;
    $this->reload();
    session()->flash('success', __('application.quality_evidence_uploaded_895704c7'));
};

$approve = function (): void {
    app(ProductionQualityService::class)->approve($this->inspection, auth()->user(), $this->decision_reason);
    $this->reload();
    session()->flash('success', __('application.quality_result_approved_only_the_approved_quantity_is_eligible_for_release_de52754d'));
};

$reject = function (): void {
    app(ProductionQualityService::class)->reject($this->inspection, auth()->user(), $this->decision_reason);
    $this->reload();
};

?>
<div>
    <x-page-header :title="$inspection->inspection_number"  :translate-title="false" description="{{ __('application.auditable_batch_quality_inspection_disposition_and_approval_4e97fae2') }}" :breadcrumbs="[__('production.quality.inspections') => route('production.quality.inspections.index'), $inspection->inspection_number => null]" />
    @if(session('success'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ session('success') }}</div>@endif

    @php
        $order = $inspection->productionOrder;
        $batch = $inspection->curingBatch;
        $unit = $inspection->product?->unit?->short_name ?: '';
        $q = fn ($value) => rtrim(rtrim(number_format((float) ($value ?? 0), 4, '.', ''), '0'), '.');
        $canRecord = auth()->user()?->canAny(['production.record_qc_result', 'production.perform_quality_inspections']);
        $canApprove = auth()->user()?->canAny(['production.approve_qc', 'production.approve_quality']);
        $showDisposition = in_array($inspection_result, ['conditional', 'failed', 'hold'], true) || (float) $rejected_quantity > 0;
    @endphp

    <div class="mb-4 flex flex-wrap gap-2 print:hidden">
        @if(in_array($inspection->result, ['failed', 'conditional', 'hold'], true) && $canRecord)
            <a href="{{ route('production.quality.inspections.create', ['retest' => $inspection->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-3 py-2 text-sm font-black text-white">{{ __('application.create_retest_208fe2b7') }}</a>
        @endif
        <button onclick="window.print()" class="rounded-lg border px-3 py-2 text-sm font-bold">{{ __('application.print_5b221e9c') }}</button>
    </div>

    <x-card title="{{ __('application.qc_context_summary_8ca7c88b') }}">
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            @foreach([
                'Planned Production' => $order?->planned_quantity,
                'Accepted Into Curing' => $batch?->accepted_quantity ?? $order?->accepted_quantity,
                'Production Reject' => $order?->rejected_quantity,
                'Current Curing Batch Quantity' => $batch?->remaining_quantity,
                'Existing Curing Damage' => $batch?->damaged_quantity,
                'Previously Released' => $batch?->released_quantity,
                'Remaining Available for QC' => $batch?->remaining_quantity ?? $inspection->applicable_quantity,
            ] as $label => $value)
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><dt class="text-xs font-bold text-slate-500">{{ $label }}</dt><dd class="mt-1 text-xl font-black">{{ $q($value) }} <span class="text-xs">{{ $unit }}</span></dd></div>
            @endforeach
        </dl>
    </x-card>

    <x-card class="mt-5"><dl class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
        <div><dt class="text-xs text-slate-500">{{ __('application.product_dd3b86d1') }}</dt><dd class="font-bold">{{ $inspection->product?->name }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.production_context_5747d464') }}</dt><dd class="font-bold">{{ $order?->order_number }} / {{ $batch?->batch_number }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.plan_version_snapshot_4801b226') }}</dt><dd class="font-bold">{{ $inspection->plan_name_snapshot ?: $inspection->plan?->name ?: __('terminology.no_plan_assigned_07c1dcb2') }} / {{ $inspection->plan_version_snapshot ?: '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.recipe_snapshot_279feaa4') }}</dt><dd class="font-bold">{{ $inspection->recipeSnapshot?->recipe_name ?: '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.stage_ca6d0e3a') }}</dt><dd class="font-bold">{{ \App\Support\UiText::label($inspection->inspection_stage) }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.inspector_8222c902') }}</dt><dd class="font-bold">{{ $inspection->inspector?->name }} · {{ $inspection->inspected_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.decision_7f59a1f1') }}</dt><dd class="font-black">{{ \App\Support\UiText::label($inspection->result) }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __('application.approval_8cc047ac') }}</dt><dd class="font-black">{{ \App\Support\UiText::label($inspection->approval_status) }}</dd></div>
    </dl></x-card>

    @if(! $inspection->production_quality_plan_id)
        <div class="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 font-bold text-amber-900">{{ __('application.no_active_qc_plan_is_assigned_to_this_product_or_product_family_ccbe7890') }}</div>
    @endif

    <x-card class="mt-5" title="{{ __('application.inspection_checklist_fc4ea3b4') }}">
        @if($inspection->results->isEmpty())
            <p class="font-bold text-amber-700">{{ __('application.no_active_qc_plan_is_assigned_to_this_product_or_product_family_ccbe7890') }}</p>
        @else
            <div class="overflow-x-auto"><x-table :headers="[__('terminology.check_4b5e84be'), __('terminology.requirement_snapshot_0c1b6eb4'), __('terminology.actual_value_27ead4b7'), __('application.unit_f6b935ab'), __('terminology.result_5faa59d4'), __('application.critical_04b7b26c'), __('application.comment_153d7a58')]">
                @foreach($inspection->results as $line)
                    <tr class="{{ $line->result === 'failed' ? 'bg-red-50 dark:bg-red-950/20' : '' }}">
                        <td class="px-3 py-3 font-bold">{{ $line->check_name }}</td>
                        <td class="px-3 py-3">{{ $line->requirement_snapshot ?: \App\Support\UiText::label($line->acceptance_rule) }}</td>
                        <td class="min-w-48 px-3 py-3">
                            @if($inspection->result === 'pending' && $canRecord)
                                @if($line->check_type === 'numeric')
                                    <input type="number" step="any" wire:model="check_answers.{{ $line->id }}.numeric_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">
                                @elseif($line->check_type === 'yes_no')
                                    <select wire:model="check_answers.{{ $line->id }}.boolean_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.pending_96f608c1') }}</option><option value="1">{{ __('application.yes_5397e058') }}</option><option value="0">{{ __('application.no_816c52fd') }}</option></select>
                                @elseif($line->check_type === 'selection')
                                    <select wire:model="check_answers.{{ $line->id }}.selected_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.pending_96f608c1') }}</option>@foreach($line->allowed_options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
                                @else
                                    <input wire:model="check_answers.{{ $line->id }}.text_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">
                                @endif
                                @if($line->acceptance_rule === 'manual_judgement')
                                    <select wire:model="check_answers.{{ $line->id }}.manual_result" class="mt-2 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.pending_96f608c1') }}</option><option value="passed">{{ __('application.pass_d7cd56f2') }}</option><option value="failed">{{ __('application.fail_2758e327') }}</option><option value="not_applicable">{{ __('application.not_applicable_6672b815') }}</option></select>
                                @endif
                            @else
                                {{ $line->numeric_value ?? ($line->boolean_value === null ? ($line->selected_value ?: $line->text_value) : ($line->boolean_value ? __('application.yes_5397e058') : __('application.no_816c52fd'))) ?: '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-3">{{ $line->unit_snapshot ?: $line->unit?->short_name ?: '—' }}</td>
                        <td class="px-3 py-3 font-black">{{ \App\Support\UiText::label($line->result) }}</td>
                        <td class="px-3 py-3">{{ $line->is_critical ? __('application.yes_5397e058') : __('application.no_816c52fd') }}</td>
                        <td class="min-w-48 px-3 py-3">@if($inspection->result === 'pending' && $canRecord)<input wire:model="check_answers.{{ $line->id }}.inspector_comment" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">@else{{ $line->inspector_comment ?: '—' }}@endif</td>
                    </tr>
                @endforeach
            </x-table></div>
        @endif
    </x-card>

    @if($inspection->result === 'pending' && $inspection->production_curing_batch_id && $canRecord)
        <x-card class="mt-5" title="{{ __('application.record_qc_result_49b365c5') }}">
            <form wire:submit="recordInspection" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold">{{ __('application.decision_7f59a1f1') }}<select wire:model.live="inspection_result" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="passed">{{ __('application.pass_d7cd56f2') }}</option><option value="conditional">{{ __('application.conditional_pass_76f99ffa') }}</option><option value="failed">{{ __('application.fail_2758e327') }}</option><option value="hold">{{ __('application.hold_3bd32832') }}</option></select>@error('result')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">{{ __('application.qc_accepted_quantity_2e2b8425') }}<input type="number" min="0" step="0.0001" wire:model.live="accepted_quantity" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><span class="mt-1 block text-xs font-normal text-slate-500">{{ __('application.units_from_the_current_curing_batch_that_passed_this_inspection_2e863e8f') }}</span>@error('accepted_quantity')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">{{ __('application.qc_rejected_quantity_45606b8f') }}<input type="number" min="0" step="0.0001" wire:model.live="rejected_quantity" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><span class="mt-1 block text-xs font-normal text-slate-500">{{ __('application.units_from_the_current_curing_batch_rejected_during_this_inspection_this_do_e1a6993b') }}</span>@error('rejected_quantity')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">{{ __('application.inspector_8222c902') }}<select wire:model="inspector_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950">@foreach(User::query()->where('company_id', $inspection->company_id)->where('status', 'active')->orderBy('name')->get()->filter(fn ($candidate) => $candidate->canAny(['production.record_qc_result', 'production.perform_quality_inspections'])) as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach</select></label>
                </div>
                @if($showDisposition)
                    <div class="grid gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4 md:grid-cols-2 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <label class="text-sm font-bold">{{ __('application.reason_justification_24c6f34b') }}<textarea wire:model="reason_justification" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>@error('reason_justification')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label class="text-sm font-bold">{{ __('application.corrective_action_release_condition_731d4937') }}<textarea wire:model="corrective_action" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>@error('corrective_action')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label class="text-sm font-bold">{{ __('application.disposition_009ce48f') }}<select wire:model="disposition" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.select_disposition_9367271b') }}</option>@foreach(ProductionQualityInspection::DISPOSITIONS as $option)<option value="{{ $option }}">{{ \App\Support\UiText::label($option) }}</option>@endforeach</select>@error('disposition')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <div><label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model.live="retest_required">{{ __('application.retest_required_85fb9757') }}</label>@if($retest_required)<input type="date" wire:model="retest_date" class="mt-2 block w-full rounded-lg border-slate-200 dark:bg-navy-950">@error('retest_date')<span class="text-xs text-red-600">{{ $message }}</span>@enderror @endif</div>
                    </div>
                @endif
                <label class="block text-sm font-bold">{{ __('application.inspection_notes_606ff18b') }}<textarea wire:model="inspection_notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea></label>
                <button class="rounded-lg bg-build-orange px-4 py-2 font-black text-white" @disabled(! $inspection->production_quality_plan_id)>{{ __('application.record_quality_result_ee467c36') }}</button>
            </form>
        </x-card>
    @endif

    <x-card class="mt-5" title="{{ __('application.photos_and_attachments_c352e2f8') }}">
        @if($canRecord && $inspection->approval_status !== 'approved')
            <form wire:submit="uploadEvidence" class="mb-4 grid gap-3 md:grid-cols-[220px_1fr_auto]">
                <select wire:model="evidence_category" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="product_photo">{{ __('application.product_photo_089f1396') }}</option><option value="damage_photo">{{ __('application.damage_photo_859ba158') }}</option><option value="test_result">{{ __('application.test_result_cdff3573') }}</option><option value="laboratory_certificate">{{ __('application.laboratory_certificate_eebc3566') }}</option><option value="other">{{ __('application.other_6e6a6f20') }}</option></select>
                <input type="file" wire:model="evidence" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" class="rounded-lg border p-2"> <button class="rounded-lg border px-4 py-2 font-bold">{{ __('application.upload_8bdf057f') }}</button>
                @error('evidence')<span class="text-sm text-red-600 md:col-span-3">{{ $message }}</span>@enderror
            </form>
        @endif
        <div class="space-y-2">@forelse($inspection->attachments as $attachment)<div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/5"><b>{{ $attachment->original_name }}</b> · {{ \App\Support\UiText::label($attachment->category) }} · {{ number_format($attachment->size_bytes / 1024, 1) }} KB<br><span class="text-xs text-slate-500">{{ __('application.uploaded_by_4fdc58b2') }} {{ $attachment->uploader?->name ?: __('application.system_bc0792d8') }} {{ __('application.at_27e90dfa') }} {{ $attachment->uploaded_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</span></div>@empty<p class="text-sm text-slate-500">{{ __('application.no_evidence_uploaded_f6340797') }}</p>@endforelse</div>
    </x-card>

    @if($inspection->approval_status === 'pending' && $inspection->result !== 'pending' && $canApprove)
        <x-card class="mt-5" title="{{ __('application.approval_decision_a0b68f4e') }}">
            @if($inspection->plan?->enforce_approval_separation)<p class="mb-3 text-sm font-bold text-amber-700">{{ __('application.segregation_of_duties_is_enabled_the_inspector_cannot_self_approve_without__228c9f84') }}</p>@endif
            <textarea wire:model="decision_reason" placeholder="{{ __('application.approval_or_rejection_reason_63712475') }}" class="block min-h-24 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>
            @error('approval')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @error('approval_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @error('rejection_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="mt-3 flex gap-2"><button wire:click="approve" class="rounded-lg bg-emerald-600 px-4 py-2 font-black text-white">{{ __('application.approve_7b2c7f14') }}</button><button wire:click="reject" class="rounded-lg bg-red-600 px-4 py-2 font-black text-white">{{ __('application.reject_2b03b592') }}</button></div>
        </x-card>
    @endif

    <div class="mt-5 grid gap-5 md:grid-cols-2">
        <x-card title="{{ __('application.disposition_and_quantities_5ada247c') }}"><p>{{ __('application.qc_accepted_9646a691') }} <b>{{ $q($inspection->passed_quantity) }} {{ $unit }}</b></p><p>{{ __('application.qc_rejected_4165b05b') }} <b>{{ $q($inspection->failed_quantity) }} {{ $unit }}</b></p><p>{{ __('application.disposition_bb0cbfbd') }} <b>{{ $inspection->disposition ? \App\Support\UiText::label($inspection->disposition) : '—' }}</b></p><p>{{ __('application.corrective_action_f9c0d4cf') }} {{ $inspection->corrective_action ?: '—' }}</p><p>{{ __('application.retest_fead190c') }} {{ $inspection->retest_required ? __('application.required_eed6bfb4').($inspection->retest_date ? ' on '.$inspection->retest_date->locale(app()->getLocale())->translatedFormat('d M Y') : '') : __('application.not_required_45240f7a') }}</p></x-card>
        <x-card title="{{ __('application.audit_timeline_11fb4caf') }}"><div class="space-y-3">@forelse($inspection->auditEvents as $event)<div class="border-l-2 border-cyan-400 pl-3"><b>{{ \App\Support\UiText::label($event->event_type) }}</b><br><span class="text-xs text-slate-500">{{ $event->user?->name ?: __('application.system_bc0792d8') }} · {{ $event->occurred_at->locale(app()->getLocale())->translatedFormat('d M Y H:i:s') }} · {{ $event->reference_number }}</span>@if($event->reason)<p class="text-sm">{{ $event->reason }}</p>@endif</div>@empty<p class="text-sm text-slate-500">{{ __('application.historical_inspection_structured_audit_events_begin_with_the_next_action_044a52cf') }}</p>@endforelse</div></x-card>
    </div>
</div>
