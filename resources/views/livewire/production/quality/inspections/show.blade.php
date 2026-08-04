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
    session()->flash('success', 'Quality inspection recorded and is ready for approval.');
};

$uploadEvidence = function (): void {
    $this->validate([
        'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        'evidence_category' => ['required', 'in:product_photo,damage_photo,test_result,laboratory_certificate,other'],
    ]);
    app(ProductionQualityService::class)->addAttachment($this->inspection, $this->evidence, $this->evidence_category, auth()->user());
    $this->evidence = null;
    $this->reload();
    session()->flash('success', 'Quality evidence uploaded.');
};

$approve = function (): void {
    app(ProductionQualityService::class)->approve($this->inspection, auth()->user(), $this->decision_reason);
    $this->reload();
    session()->flash('success', 'Quality result approved; only the approved quantity is eligible for release.');
};

$reject = function (): void {
    app(ProductionQualityService::class)->reject($this->inspection, auth()->user(), $this->decision_reason);
    $this->reload();
};

?>
<div>
    <x-page-header :title="$inspection->inspection_number" description="Auditable batch quality inspection, disposition, and approval." :breadcrumbs="[__('production.quality.inspections') => route('production.quality.inspections.index'), $inspection->inspection_number => null]" />
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
            <a href="{{ route('production.quality.inspections.create', ['retest' => $inspection->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-3 py-2 text-sm font-black text-white">Create retest</a>
        @endif
        <button onclick="window.print()" class="rounded-lg border px-3 py-2 text-sm font-bold">Print</button>
    </div>

    <x-card title="QC Context Summary">
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
        <div><dt class="text-xs text-slate-500">Product</dt><dd class="font-bold">{{ $inspection->product?->name }}</dd></div>
        <div><dt class="text-xs text-slate-500">Production Context</dt><dd class="font-bold">{{ $order?->order_number }} / {{ $batch?->batch_number }}</dd></div>
        <div><dt class="text-xs text-slate-500">Plan / Version Snapshot</dt><dd class="font-bold">{{ $inspection->plan_name_snapshot ?: $inspection->plan?->name ?: 'No plan assigned' }} / {{ $inspection->plan_version_snapshot ?: '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Recipe Snapshot</dt><dd class="font-bold">{{ $inspection->recipeSnapshot?->recipe_name ?: '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Stage</dt><dd class="font-bold">{{ str($inspection->inspection_stage)->headline() }}</dd></div>
        <div><dt class="text-xs text-slate-500">Inspector</dt><dd class="font-bold">{{ $inspection->inspector?->name }} · {{ $inspection->inspected_at->format('d M Y H:i') }}</dd></div>
        <div><dt class="text-xs text-slate-500">Decision</dt><dd class="font-black">{{ str($inspection->result)->headline() }}</dd></div>
        <div><dt class="text-xs text-slate-500">Approval</dt><dd class="font-black">{{ str($inspection->approval_status)->headline() }}</dd></div>
    </dl></x-card>

    @if(! $inspection->production_quality_plan_id)
        <div class="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 font-bold text-amber-900">No active QC plan is assigned to this product or product family.</div>
    @endif

    <x-card class="mt-5" title="Inspection Checklist">
        @if($inspection->results->isEmpty())
            <p class="font-bold text-amber-700">No active QC plan is assigned to this product or product family.</p>
        @else
            <div class="overflow-x-auto"><x-table :headers="['Check', 'Requirement Snapshot', 'Actual Value', 'Unit', 'Result', 'Critical', 'Comment']">
                @foreach($inspection->results as $line)
                    <tr class="{{ $line->result === 'failed' ? 'bg-red-50 dark:bg-red-950/20' : '' }}">
                        <td class="px-3 py-3 font-bold">{{ $line->check_name }}</td>
                        <td class="px-3 py-3">{{ $line->requirement_snapshot ?: str($line->acceptance_rule)->headline() }}</td>
                        <td class="min-w-48 px-3 py-3">
                            @if($inspection->result === 'pending' && $canRecord)
                                @if($line->check_type === 'numeric')
                                    <input type="number" step="any" wire:model="check_answers.{{ $line->id }}.numeric_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">
                                @elseif($line->check_type === 'yes_no')
                                    <select wire:model="check_answers.{{ $line->id }}.boolean_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Pending</option><option value="1">Yes</option><option value="0">No</option></select>
                                @elseif($line->check_type === 'selection')
                                    <select wire:model="check_answers.{{ $line->id }}.selected_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Pending</option>@foreach($line->allowed_options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
                                @else
                                    <input wire:model="check_answers.{{ $line->id }}.text_value" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">
                                @endif
                                @if($line->acceptance_rule === 'manual_judgement')
                                    <select wire:model="check_answers.{{ $line->id }}.manual_result" class="mt-2 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Pending</option><option value="passed">Pass</option><option value="failed">Fail</option><option value="not_applicable">Not Applicable</option></select>
                                @endif
                            @else
                                {{ $line->numeric_value ?? ($line->boolean_value === null ? ($line->selected_value ?: $line->text_value) : ($line->boolean_value ? 'Yes' : 'No')) ?: '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-3">{{ $line->unit_snapshot ?: $line->unit?->short_name ?: '—' }}</td>
                        <td class="px-3 py-3 font-black">{{ str($line->result)->headline() }}</td>
                        <td class="px-3 py-3">{{ $line->is_critical ? 'Yes' : 'No' }}</td>
                        <td class="min-w-48 px-3 py-3">@if($inspection->result === 'pending' && $canRecord)<input wire:model="check_answers.{{ $line->id }}.inspector_comment" class="w-full rounded-lg border-slate-200 dark:bg-navy-950">@else{{ $line->inspector_comment ?: '—' }}@endif</td>
                    </tr>
                @endforeach
            </x-table></div>
        @endif
    </x-card>

    @if($inspection->result === 'pending' && $inspection->production_curing_batch_id && $canRecord)
        <x-card class="mt-5" title="Record QC Result">
            <form wire:submit="recordInspection" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold">Decision<select wire:model.live="inspection_result" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="passed">Pass</option><option value="conditional">Conditional Pass</option><option value="failed">Fail</option><option value="hold">Hold</option></select>@error('result')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">QC Accepted Quantity<input type="number" min="0" step="0.0001" wire:model.live="accepted_quantity" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><span class="mt-1 block text-xs font-normal text-slate-500">Units from the current curing batch that passed this inspection.</span>@error('accepted_quantity')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">QC Rejected Quantity<input type="number" min="0" step="0.0001" wire:model.live="rejected_quantity" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><span class="mt-1 block text-xs font-normal text-slate-500">Units from the current curing batch rejected during this inspection. This does not include production rejects.</span>@error('rejected_quantity')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm font-bold">Inspector<select wire:model="inspector_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950">@foreach(User::query()->where('company_id', $inspection->company_id)->where('status', 'active')->orderBy('name')->get()->filter(fn ($candidate) => $candidate->canAny(['production.record_qc_result', 'production.perform_quality_inspections'])) as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach</select></label>
                </div>
                @if($showDisposition)
                    <div class="grid gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4 md:grid-cols-2 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <label class="text-sm font-bold">Reason / Justification<textarea wire:model="reason_justification" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>@error('reason_justification')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label class="text-sm font-bold">Corrective Action / Release Condition<textarea wire:model="corrective_action" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>@error('corrective_action')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label class="text-sm font-bold">Disposition<select wire:model="disposition" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Select disposition</option>@foreach(ProductionQualityInspection::DISPOSITIONS as $option)<option value="{{ $option }}">{{ str($option)->headline() }}</option>@endforeach</select>@error('disposition')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <div><label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model.live="retest_required">Retest Required</label>@if($retest_required)<input type="date" wire:model="retest_date" class="mt-2 block w-full rounded-lg border-slate-200 dark:bg-navy-950">@error('retest_date')<span class="text-xs text-red-600">{{ $message }}</span>@enderror @endif</div>
                    </div>
                @endif
                <label class="block text-sm font-bold">Inspection Notes<textarea wire:model="inspection_notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea></label>
                <button class="rounded-lg bg-build-orange px-4 py-2 font-black text-white" @disabled(! $inspection->production_quality_plan_id)>Record Quality Result</button>
            </form>
        </x-card>
    @endif

    <x-card class="mt-5" title="Photos and Attachments">
        @if($canRecord && $inspection->approval_status !== 'approved')
            <form wire:submit="uploadEvidence" class="mb-4 grid gap-3 md:grid-cols-[220px_1fr_auto]">
                <select wire:model="evidence_category" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="product_photo">Product Photo</option><option value="damage_photo">Damage Photo</option><option value="test_result">Test Result</option><option value="laboratory_certificate">Laboratory Certificate</option><option value="other">Other</option></select>
                <input type="file" wire:model="evidence" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" class="rounded-lg border p-2"> <button class="rounded-lg border px-4 py-2 font-bold">Upload</button>
                @error('evidence')<span class="text-sm text-red-600 md:col-span-3">{{ $message }}</span>@enderror
            </form>
        @endif
        <div class="space-y-2">@forelse($inspection->attachments as $attachment)<div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/5"><b>{{ $attachment->original_name }}</b> · {{ str($attachment->category)->headline() }} · {{ number_format($attachment->size_bytes / 1024, 1) }} KB<br><span class="text-xs text-slate-500">Uploaded by {{ $attachment->uploader?->name ?: 'System' }} at {{ $attachment->uploaded_at->format('d M Y H:i') }}</span></div>@empty<p class="text-sm text-slate-500">No evidence uploaded.</p>@endforelse</div>
    </x-card>

    @if($inspection->approval_status === 'pending' && $inspection->result !== 'pending' && $canApprove)
        <x-card class="mt-5" title="Approval Decision">
            @if($inspection->plan?->enforce_approval_separation)<p class="mb-3 text-sm font-bold text-amber-700">Segregation of duties is enabled: the inspector cannot self-approve without explicit override permission and an audit reason.</p>@endif
            <textarea wire:model="decision_reason" placeholder="Approval or rejection reason" class="block min-h-24 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>
            @error('approval')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @error('approval_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @error('rejection_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="mt-3 flex gap-2"><button wire:click="approve" class="rounded-lg bg-emerald-600 px-4 py-2 font-black text-white">Approve</button><button wire:click="reject" class="rounded-lg bg-red-600 px-4 py-2 font-black text-white">Reject</button></div>
        </x-card>
    @endif

    <div class="mt-5 grid gap-5 md:grid-cols-2">
        <x-card title="Disposition and Quantities"><p>QC Accepted: <b>{{ $q($inspection->passed_quantity) }} {{ $unit }}</b></p><p>QC Rejected: <b>{{ $q($inspection->failed_quantity) }} {{ $unit }}</b></p><p>Disposition: <b>{{ $inspection->disposition ? str($inspection->disposition)->headline() : '—' }}</b></p><p>Corrective Action: {{ $inspection->corrective_action ?: '—' }}</p><p>Retest: {{ $inspection->retest_required ? 'Required'.($inspection->retest_date ? ' on '.$inspection->retest_date->format('d M Y') : '') : 'Not required' }}</p></x-card>
        <x-card title="Audit Timeline"><div class="space-y-3">@forelse($inspection->auditEvents as $event)<div class="border-l-2 border-cyan-400 pl-3"><b>{{ str($event->event_type)->headline() }}</b><br><span class="text-xs text-slate-500">{{ $event->user?->name ?: 'System' }} · {{ $event->occurred_at->format('d M Y H:i:s') }} · {{ $event->reference_number }}</span>@if($event->reason)<p class="text-sm">{{ $event->reason }}</p>@endif</div>@empty<p class="text-sm text-slate-500">Historical inspection; structured audit events begin with the next action.</p>@endforelse</div></x-card>
    </div>
</div>
