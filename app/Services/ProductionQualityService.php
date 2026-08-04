<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionQualityAttachment;
use App\Models\ProductionQualityAuditEvent;
use App\Models\ProductionQualityHold;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionQualityPlan;
use App\Models\ProductionQualityPlanCheck;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CompanyFeatures;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionQualityService
{
    public function __construct(private ProductionRecipeCalculator $calculator) {}

    public function activatePlan(ProductionQualityPlan $plan, User $user): ProductionQualityPlan
    {
        $this->authorize($user, 'production.manage_quality_plans');

        return DB::transaction(function () use ($plan, $user): ProductionQualityPlan {
            $plan = ProductionQualityPlan::query()->forCurrentCompany()->whereKey($plan->id)->with(['product', 'checks'])->lockForUpdate()->firstOrFail();
            $this->sameCompany($plan->company_id, $user);
            if ($plan->status !== 'draft' || ! $plan->product?->isManufactured() || $plan->checks->isEmpty()) {
                throw ValidationException::withMessages(['plan' => 'Only a draft plan with checks for a manufactured product can be activated.']);
            }
            ProductionQualityPlan::query()->where('company_id', $plan->company_id)->where('product_id', $plan->product_id)
                ->where('inspection_stage', $plan->inspection_stage)->where('status', 'active')->lockForUpdate()->get()
                ->each(fn (ProductionQualityPlan $active) => $active->update(['status' => 'inactive', 'updated_by' => $user->id]));
            $plan->update(['status' => 'active', 'updated_by' => $user->id]);

            return $plan->refresh();
        }, 3);
    }

    public function deactivatePlan(ProductionQualityPlan $plan, User $user): ProductionQualityPlan
    {
        $this->authorize($user, 'production.manage_quality_plans');
        $this->sameCompany($plan->company_id, $user);
        if ($plan->status !== 'active') {
            throw ValidationException::withMessages(['plan' => 'Only an active plan can be deactivated.']);
        }
        $plan->update(['status' => 'inactive', 'updated_by' => $user->id]);

        return $plan->refresh();
    }

    public function queueCuringInspection(ProductionOrder $order, ProductionCuringBatch $batch, User $user): ?ProductionQualityInspection
    {
        $this->sameCompany($order->company_id, $user);
        if (! $order->product?->requires_quality_control) {
            return null;
        }

        return DB::transaction(function () use ($order, $batch, $user): ProductionQualityInspection {
            $existing = ProductionQualityInspection::query()
                ->where('company_id', $order->company_id)
                ->where('production_curing_batch_id', $batch->id)
                ->where('inspection_stage', 'pre_release')
                ->whereNull('supersedes_inspection_id')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $plan = ProductionQualityPlan::query()
                ->where('company_id', $order->company_id)
                ->where('product_id', $order->product_id)
                ->where('inspection_stage', 'pre_release')
                ->where('status', 'active')
                ->with('checks.unit')->first();

            $inspection = ProductionQualityInspection::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'production_quality_plan_id' => $plan?->id,
                'plan_name_snapshot' => $plan?->name,
                'plan_version_snapshot' => $plan?->version,
                'production_order_id' => $order->id,
                'production_curing_batch_id' => $batch->id,
                'recipe_snapshot_id' => $order->snapshot()->value('id'),
                'product_id' => $order->product_id,
                'machine_id' => $order->machine_id,
                'inspection_number' => $this->nextNumber($order->company_id, DocumentSequence::QUALITY_INSPECTION, 'QIN', true),
                'inspection_stage' => 'pre_release',
                'applicable_quantity' => $order->accepted_quantity,
                'result' => 'pending',
                'approval_status' => 'pending',
                'inspected_at' => now(),
                'inspected_by' => $user->id,
                'notes' => 'Automatically queued when production completed.',
            ]);
            if ($plan) {
                $this->snapshotChecks($inspection, $plan);
            }
            $this->audit($inspection, 'inspection_created', $user, null, [
                'result' => 'pending', 'approval_status' => 'pending', 'applicable_quantity' => (string) $order->accepted_quantity,
            ], 'Automatically queued when production completed.');

            return $inspection->load('results.unit');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordQueuedInspection(ProductionQualityInspection $inspection, array $data, User $user): ProductionQualityInspection
    {
        $this->authorizeAny($user, ['production.record_qc_result', 'production.perform_quality_inspections']);

        return DB::transaction(function () use ($inspection, $data, $user): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)
                ->whereKey($inspection->id)->with(['plan.checks.unit', 'results', 'attachments'])->lockForUpdate()->firstOrFail();
            if ($inspection->result !== 'pending' || ! $inspection->production_curing_batch_id) {
                throw ValidationException::withMessages(['inspection' => 'Only a queued curing inspection may be recorded.']);
            }

            $result = (string) ($data['result'] ?? '');
            if (! in_array($result, ['passed', 'conditional', 'failed', 'hold'], true)) {
                throw ValidationException::withMessages(['result' => 'Select Pass, Conditional Pass, Fail, or Hold.']);
            }
            $batch = ProductionCuringBatch::query()->where('company_id', $inspection->company_id)
                ->whereKey($inspection->production_curing_batch_id)->lockForUpdate()->firstOrFail();
            if (! $inspection->production_quality_plan_id || ! $inspection->plan) {
                throw ValidationException::withMessages(['plan' => 'No active QC plan is assigned to this product or product family.']);
            }
            if ($inspection->results->isEmpty()) {
                $this->snapshotChecks($inspection, $inspection->plan);
                $inspection->load('results');
            }
            $available = (string) $batch->remaining_quantity;
            $quantities = $this->validateQuantities([
                'inspected_quantity' => $available,
                'passed_quantity' => $data['accepted_quantity'] ?? null,
                'failed_quantity' => $data['rejected_quantity'] ?? null,
            ], $available);
            $accepted = $quantities['passed_quantity'] ?? '0';
            $rejected = $quantities['failed_quantity'] ?? '0';
            if (bccomp(bcadd($accepted, $rejected, 12), $available, 12) !== 0) {
                throw ValidationException::withMessages(['accepted_quantity' => 'QC accepted plus QC rejected quantity must equal the curing quantity currently available for inspection.']);
            }
            $reason = trim((string) ($data['reason_justification'] ?? ''));
            $correctiveAction = trim((string) ($data['corrective_action'] ?? ''));
            $disposition = filled($data['disposition'] ?? null) ? (string) $data['disposition'] : null;
            $retestRequired = (bool) ($data['retest_required'] ?? false);
            $retestDate = filled($data['retest_date'] ?? null) ? (string) $data['retest_date'] : null;
            if (in_array($result, ['conditional', 'failed', 'hold'], true)) {
                if ($reason === '') {
                    throw ValidationException::withMessages(['reason_justification' => 'Reason / Justification is required for this QC decision.']);
                }
                if ($correctiveAction === '') {
                    throw ValidationException::withMessages(['corrective_action' => 'Corrective Action is required for this QC decision.']);
                }
                if (! in_array($disposition, ProductionQualityInspection::DISPOSITIONS, true)) {
                    throw ValidationException::withMessages(['disposition' => 'Select a valid disposition.']);
                }
            }
            if (bccomp($rejected, '0', 12) > 0 && ! in_array($disposition, ProductionQualityInspection::DISPOSITIONS, true)) {
                throw ValidationException::withMessages(['disposition' => 'A disposition is required when QC rejects quantity.']);
            }
            if ($retestRequired && ! $retestDate) {
                throw ValidationException::withMessages(['retest_date' => 'Retest Date is required when a retest is requested.']);
            }

            $inspector = User::query()->where('company_id', $inspection->company_id)
                ->where('status', 'active')->findOrFail((int) ($data['inspector_id'] ?? 0));
            abort_unless($inspector->can('production.perform_quality_inspections'), 422);
            $this->assertBranchAccess($inspection->branch_id, $inspector);

            $this->recordChecklistAnswers($inspection, (array) ($data['check_answers'] ?? []));
            $criticalFailed = $inspection->results()->where('is_critical', true)->where('result', 'failed')->exists();
            if ($result === 'passed' && $criticalFailed) {
                throw ValidationException::withMessages(['result' => 'A failed critical check prevents a final Pass decision.']);
            }
            if (in_array($result, ['failed', 'hold'], true) && $inspection->plan->requires_failure_evidence && $inspection->attachments->isEmpty()) {
                throw ValidationException::withMessages(['evidence' => 'This QC plan requires evidence for a Fail or Hold decision.']);
            }

            $previous = $inspection->only(['result', 'approval_status', 'passed_quantity', 'failed_quantity']);
            $inspection->update([
                'applicable_quantity' => $available,
                'inspected_quantity' => $available,
                'passed_quantity' => $accepted,
                'failed_quantity' => $rejected,
                'result' => $result,
                'inspected_at' => now(),
                'inspected_by' => $inspector->id,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'reason_justification' => $reason ?: null,
                'corrective_action' => $correctiveAction ?: null,
                'disposition' => $disposition,
                'retest_required' => $retestRequired,
                'retest_date' => $retestDate,
            ]);
            if (in_array($result, ['failed', 'hold'], true) || $criticalFailed) {
                $this->maintainAutomaticHold($inspection->refresh(), $inspector);
                $batch->update([
                    'status' => match (true) {
                        $result === 'hold' => ProductionCuringBatch::STATUS_ON_HOLD,
                        $disposition === 'rework' => ProductionCuringBatch::STATUS_REWORK_REQUIRED,
                        $disposition === 'await_retest' || $retestRequired => ProductionCuringBatch::STATUS_AWAITING_RETEST,
                        default => ProductionCuringBatch::STATUS_QUARANTINED,
                    },
                    'quarantine_reason' => $reason ?: null,
                    'updated_by' => $inspector->id,
                ]);
            }
            $this->audit($inspection, 'qc_result_recorded', $user, $previous, $inspection->only([
                'result', 'approval_status', 'passed_quantity', 'failed_quantity', 'disposition', 'retest_required', 'retest_date',
            ]), $reason ?: null);
            $this->audit($inspection, 'checklist_completed', $user, null, [
                'checks' => $inspection->results()->count(),
                'critical_failed' => $criticalFailed,
            ]);

            return $inspection->refresh()->load(['results.unit', 'attachments', 'auditEvents.user']);
        }, 3);
    }

    public function duplicatePlan(ProductionQualityPlan $source, User $user): ProductionQualityPlan
    {
        $this->authorize($user, 'production.manage_quality_plans');
        $this->sameCompany($source->company_id, $user);

        return DB::transaction(function () use ($source, $user): ProductionQualityPlan {
            $source->loadMissing('checks');
            $copy = ProductionQualityPlan::query()->create([
                ...$source->only(['company_id', 'product_id', 'name', 'code', 'version', 'inspection_stage', 'effective_from', 'effective_to', 'requires_approval', 'enforce_approval_separation', 'requires_failure_evidence', 'notes']),
                'name' => $source->name.' (Copy)', 'status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            foreach ($source->checks as $check) {
                $copy->checks()->create([...$check->only(['company_id', 'name', 'description', 'check_type', 'unit_id', 'minimum_value', 'maximum_value', 'target_value', 'allowed_options', 'required', 'critical', 'acceptance_rule', 'sort_order', 'notes'])]);
            }

            return $copy->load('checks');
        });
    }

    /** @param array<int|string, array<string, mixed>> $answers */
    public function createInspection(array $data, array $answers, User $user): ProductionQualityInspection
    {
        $this->authorize($user, 'production.perform_quality_inspections');

        return DB::transaction(function () use ($data, $answers, $user): ProductionQualityInspection {
            $order = filled($data['production_order_id'] ?? null)
                ? ProductionOrder::query()->forCurrentCompany()->whereKey($data['production_order_id'])->lockForUpdate()->firstOrFail() : null;
            $batch = filled($data['production_curing_batch_id'] ?? null)
                ? ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo($user)->whereKey($data['production_curing_batch_id'])->lockForUpdate()->firstOrFail() : null;
            if (($order === null) === ($batch === null)) {
                throw ValidationException::withMessages(['context' => 'Select exactly one production order or curing batch.']);
            }
            $context = $batch ?: $order;
            $this->assertBranchAccess($context->branch_id, $user);
            $stage = (string) ($data['inspection_stage'] ?? '');
            $allowed = $batch ? ['curing', 'pre_release'] : ['raw_material', 'during_production', 'production_completion'];
            if (! in_array($stage, $allowed, true)) {
                throw ValidationException::withMessages(['inspection_stage' => 'The inspection stage does not match the selected context.']);
            }
            $product = Product::query()->where('company_id', $context->company_id)->whereKey($context->product_id)->firstOrFail();
            if (! $product->isManufactured()) {
                throw ValidationException::withMessages(['product_id' => 'Quality inspections are available only for manufactured products.']);
            }
            $plan = ProductionQualityPlan::query()->where('company_id', $context->company_id)->where('product_id', $product->id)
                ->where('inspection_stage', $stage)->where('status', 'active')->with('checks')->lockForUpdate()->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan' => 'No active quality plan exists for this product and stage.']);
            }
            $supersedesId = $data['supersedes_inspection_id'] ?? null;
            if ($supersedesId) {
                $previous = ProductionQualityInspection::query()->where('company_id', $context->company_id)->whereKey($supersedesId)->lockForUpdate()->firstOrFail();
                if (! in_array($previous->result, ['failed', 'conditional'], true)
                    || (int) $previous->product_id !== (int) $product->id
                    || (int) ($previous->production_order_id ?? 0) !== (int) ($order?->id ?? 0)
                    || (int) ($previous->production_curing_batch_id ?? 0) !== (int) ($batch?->id ?? 0)
                    || $previous->inspection_stage !== $stage) {
                    throw ValidationException::withMessages(['supersedes_inspection_id' => 'A retest must reference a failed or conditional inspection for the same context and stage.']);
                }
            }
            $applicable = (string) ($batch?->accepted_quantity ?? $order?->planned_quantity);
            $quantities = $this->validateQuantities($data, $applicable);
            $inspection = ProductionQualityInspection::query()->create([
                'company_id' => $context->company_id, 'branch_id' => $context->branch_id,
                'production_quality_plan_id' => $plan->id, 'plan_name_snapshot' => $plan->name,
                'plan_version_snapshot' => $plan->version, 'production_order_id' => $order?->id,
                'production_curing_batch_id' => $batch?->id, 'product_id' => $product->id,
                'machine_id' => $context->machine_id, 'inspection_number' => $this->nextNumber($context->company_id, DocumentSequence::QUALITY_INSPECTION, 'QIN', true),
                'inspection_stage' => $stage, 'applicable_quantity' => $applicable, ...$quantities,
                'result' => 'pending', 'approval_status' => 'pending', 'inspected_at' => $data['inspected_at'] ?? now(),
                'inspected_by' => $user->id, 'corrective_action' => $data['corrective_action'] ?? null,
                'retest_required' => (bool) ($data['retest_required'] ?? false), 'supersedes_inspection_id' => $supersedesId,
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($plan->checks as $check) {
                $answer = $answers[$check->id] ?? $answers[(string) $check->id] ?? [];
                $evaluation = $this->evaluateCheck($check, $answer);
                $inspection->results()->create([
                    'company_id' => $inspection->company_id, 'production_quality_plan_check_id' => $check->id,
                    'check_name' => $check->name, 'requirement_snapshot' => $this->requirementSnapshot($check),
                    'check_type' => $check->check_type, 'acceptance_rule' => $check->acceptance_rule,
                    'unit_id' => $check->unit_id, 'unit_snapshot' => $check->unit?->short_name ?: $check->unit?->name,
                    'plan_version_snapshot' => $plan->version, 'minimum_value' => $check->minimum_value, 'maximum_value' => $check->maximum_value,
                    'target_value' => $check->target_value, 'allowed_options' => $check->allowed_options,
                    'numeric_value' => $answer['numeric_value'] ?? null, 'boolean_value' => $answer['boolean_value'] ?? null,
                    'text_value' => $answer['text_value'] ?? null, 'selected_value' => $answer['selected_value'] ?? null,
                    'result' => $evaluation, 'is_required' => $check->required, 'is_critical' => $check->critical,
                    'inspector_comment' => $answer['inspector_comment'] ?? null,
                ]);
            }
            $result = $this->overallResult($inspection->results()->get());
            $updates = ['result' => $result];
            if (! $plan->requires_approval && $result === 'passed') {
                $updates += ['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id, 'approval_reason' => 'Plan does not require separate approval.'];
            }
            $inspection->update($updates);
            if ($result === 'failed' && $inspection->results()->where('is_critical', true)->where('result', 'failed')->exists()) {
                $this->maintainAutomaticHold($inspection, $user);
            }
            $this->audit($inspection, 'inspection_created', $user, null, [
                'result' => $inspection->result, 'approval_status' => $inspection->approval_status,
                'applicable_quantity' => $inspection->applicable_quantity,
            ]);

            return $inspection->load(['plan', 'results.unit', 'holds']);
        }, 3);
    }

    public function approve(ProductionQualityInspection $inspection, User $user, ?string $reason = null): ProductionQualityInspection
    {
        $this->authorizeAny($user, ['production.approve_qc', 'production.approve_quality']);

        return DB::transaction(function () use ($inspection, $user, $reason): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)
                ->whereKey($inspection->id)->with(['plan', 'results', 'attachments'])->lockForUpdate()->firstOrFail();
            if ($inspection->approval_status === 'approved') {
                return $inspection;
            }
            if ($inspection->approval_status !== 'pending' || ! in_array($inspection->result, ['passed', 'conditional'], true)) {
                throw ValidationException::withMessages(['approval' => 'Only a completed Pass or Conditional Pass inspection may be approved. Fail and Hold require disposition or retest.']);
            }
            if ($inspection->result === 'conditional' && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['approval_reason' => 'A justification is required to approve a conditional result.']);
            }
            if ($inspection->results->where('is_critical', true)->where('result', 'pending')->isNotEmpty()) {
                throw ValidationException::withMessages(['approval' => 'Pending critical checks must be completed before approval.']);
            }
            if ($inspection->results->where('is_critical', true)->where('result', 'failed')->isNotEmpty()) {
                throw ValidationException::withMessages(['approval' => 'A failed critical check cannot be approved for release.']);
            }
            if ($inspection->plan?->enforce_approval_separation && (int) $inspection->inspected_by === (int) $user->id) {
                if (! $user->can('production.override_qc_separation')) {
                    throw ValidationException::withMessages(['approval' => 'The inspector cannot approve their own inspection while segregation of duties is enabled.']);
                }
                if (trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['approval_reason' => 'An audit reason is required to override inspector/approver separation.']);
                }
            }

            if ($inspection->production_curing_batch_id) {
                $batch = ProductionCuringBatch::query()->where('company_id', $inspection->company_id)
                    ->whereKey($inspection->production_curing_batch_id)->lockForUpdate()->firstOrFail();
                $this->applyQcDisposition($inspection, $batch, $user);
                if (! in_array($batch->status, [ProductionCuringBatch::STATUS_RELEASED, ProductionCuringBatch::STATUS_CLOSED], true)) {
                    $releaseEligible = bcsub((string) $inspection->passed_quantity, (string) $batch->released_quantity, 12);
                    $batch->update([
                        'status' => ProductionCuringBatch::STATUS_READY_FOR_RELEASE,
                        'release_eligible_quantity' => bccomp($releaseEligible, '0', 12) < 0 ? '0' : $releaseEligible,
                        'qc_approved_at' => now(),
                        'approved_by' => $user->id,
                        'updated_by' => $user->id,
                    ]);
                }
                if ($inspection->supersedes_inspection_id) {
                    ProductionQualityHold::query()->where('company_id', $inspection->company_id)
                        ->where('production_quality_inspection_id', $inspection->supersedes_inspection_id)
                        ->where('status', 'active')->where('reason', 'like', 'Automatic hold:%')
                        ->lockForUpdate()->get()->each(fn (ProductionQualityHold $hold) => $hold->update([
                            'status' => 'released', 'released_at' => now(), 'released_by' => $user->id,
                            'release_reason' => 'Released by approved retest '.$inspection->inspection_number.'.',
                        ]));
                }
            }

            $inspection->update([
                'approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id,
                'approval_reason' => filled($reason) ? trim((string) $reason) : null,
                'qc_rejection_applied_at' => bccomp((string) $inspection->failed_quantity, '0', 12) > 0 ? now() : null,
            ]);
            $this->audit($inspection, $inspection->result === 'conditional' ? 'conditional_approval' : 'approved', $user,
                ['approval_status' => 'pending'],
                ['approval_status' => 'approved', 'release_eligible_quantity' => $inspection->passed_quantity],
                filled($reason) ? trim((string) $reason) : null);

            return $inspection->refresh()->load(['curingBatch', 'auditEvents.user']);
        });
    }

    public function reject(ProductionQualityInspection $inspection, User $user, string $reason): ProductionQualityInspection
    {
        $this->authorizeAny($user, ['production.approve_qc', 'production.approve_quality']);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($inspection, $user, $reason): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($inspection->approval_status === 'rejected') {
                return $inspection;
            }
            if ($inspection->approval_status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'This inspection has already been decided.']);
            }
            $inspection->update(['approval_status' => 'rejected', 'approved_at' => now(), 'approved_by' => $user->id, 'rejection_reason' => trim($reason)]);
            $this->audit($inspection, 'rejected', $user, ['approval_status' => 'pending'], ['approval_status' => 'rejected'], trim($reason));

            return $inspection->refresh();
        }, 3);
    }

    public function placeHold(array $data, User $user): ProductionQualityHold
    {
        $this->authorize($user, 'production.manage_quality_holds');

        return DB::transaction(fn () => $this->createHold($data, $user));
    }

    public function releaseHold(ProductionQualityHold $hold, User $user, string $reason): ProductionQualityHold
    {
        $this->authorize($user, 'production.manage_quality_holds');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['release_reason' => 'A release reason is required.']);
        }

        return DB::transaction(function () use ($hold, $user, $reason): ProductionQualityHold {
            $hold = ProductionQualityHold::query()->forCurrentCompany()->accessibleTo($user)->whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($hold->status !== 'active') {
                throw ValidationException::withMessages(['hold' => 'Only an active hold can be released.']);
            }
            $hold->update(['status' => 'released', 'released_at' => now(), 'released_by' => $user->id, 'release_reason' => trim($reason)]);

            return $hold->refresh();
        });
    }

    public function assertReleaseEligible(ProductionCuringBatch $batch, ?string $quantity = null): void
    {
        $this->assertNoActiveHold($batch);
        if (! $batch->product?->requires_pre_release_inspection) {
            return;
        }
        $approved = ProductionQualityInspection::query()->where('company_id', $batch->company_id)->where('production_curing_batch_id', $batch->id)
            ->where('product_id', $batch->product_id)->where('inspection_stage', 'pre_release')->whereIn('result', ['passed', 'conditional'])->where('approval_status', 'approved')
            ->whereDoesntHave('retests', fn ($q) => $q->where(fn ($x) => $x->where('result', 'failed')->orWhere('approval_status', 'rejected')))
            ->latest('inspected_at')->first();
        if (! $approved) {
            throw ValidationException::withMessages(['release_quantity' => __('production.quality.pre_release_required')]);
        }
        if ($quantity !== null && bccomp($quantity, (string) $batch->release_eligible_quantity, 12) > 0) {
            throw ValidationException::withMessages(['release_quantity' => 'Release quantity exceeds the quantity approved by quality inspection.']);
        }
    }

    public function assertNoActiveHold(ProductionCuringBatch $batch): void
    {
        if (ProductionQualityHold::query()->where('company_id', $batch->company_id)->where('production_curing_batch_id', $batch->id)->active()->exists()) {
            throw ValidationException::withMessages(['release_quantity' => __('production.quality.active_hold_blocks_release')]);
        }
    }

    public function addAttachment(ProductionQualityInspection $inspection, UploadedFile $file, string $category, User $user): ProductionQualityAttachment
    {
        $this->authorizeAny($user, ['production.record_qc_result', 'production.perform_quality_inspections']);
        if (! in_array($category, ['product_photo', 'damage_photo', 'test_result', 'laboratory_certificate', 'other'], true)) {
            throw ValidationException::withMessages(['evidence_category' => 'Select a valid evidence category.']);
        }
        $allowed = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (! in_array((string) $file->getMimeType(), $allowed, true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['evidence' => 'Evidence must be a JPG, PNG, PDF, Word, or Excel file no larger than 10 MB.']);
        }

        return DB::transaction(function () use ($inspection, $file, $category, $user): ProductionQualityAttachment {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)
                ->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $path = $file->store('production-quality/'.$inspection->company_id.'/'.$inspection->id, 'local');
            $attachment = ProductionQualityAttachment::query()->create([
                'company_id' => $inspection->company_id,
                'production_quality_inspection_id' => $inspection->id,
                'category' => $category,
                'original_name' => $file->getClientOriginalName(),
                'storage_disk' => 'local',
                'storage_path' => $path,
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
            ]);
            $this->audit($inspection, 'attachment_uploaded', $user, null, [
                'attachment_id' => $attachment->id, 'category' => $category, 'name' => $attachment->original_name,
            ]);

            return $attachment->load('uploader');
        }, 3);
    }

    public function overallResult(iterable $results): string
    {
        $requiredPending = $criticalFailed = $nonCriticalFailed = false;
        foreach ($results as $result) {
            $requiredPending = $requiredPending || ($result->is_required && $result->result === 'pending');
            $criticalFailed = $criticalFailed || ($result->is_critical && $result->result === 'failed');
            $nonCriticalFailed = $nonCriticalFailed || (! $result->is_critical && $result->result === 'failed');
        }

        return $requiredPending ? 'pending' : ($criticalFailed ? 'failed' : ($nonCriticalFailed ? 'conditional' : 'passed'));
    }

    private function snapshotChecks(ProductionQualityInspection $inspection, ProductionQualityPlan $plan): void
    {
        $plan->loadMissing('checks.unit');
        foreach ($plan->checks as $check) {
            $inspection->results()->firstOrCreate(
                ['production_quality_plan_check_id' => $check->id],
                [
                    'company_id' => $inspection->company_id,
                    'check_name' => $check->name,
                    'requirement_snapshot' => $this->requirementSnapshot($check),
                    'check_type' => $check->check_type,
                    'acceptance_rule' => $check->acceptance_rule,
                    'unit_id' => $check->unit_id,
                    'unit_snapshot' => $check->unit?->short_name ?: $check->unit?->name,
                    'plan_version_snapshot' => $plan->version,
                    'minimum_value' => $check->minimum_value,
                    'maximum_value' => $check->maximum_value,
                    'target_value' => $check->target_value,
                    'allowed_options' => $check->allowed_options,
                    'result' => 'pending',
                    'is_required' => $check->required,
                    'is_critical' => $check->critical,
                ]
            );
        }
    }

    /** @param array<int|string, array<string, mixed>> $answers */
    private function recordChecklistAnswers(ProductionQualityInspection $inspection, array $answers): void
    {
        foreach ($inspection->results as $line) {
            $answer = $answers[$line->id] ?? $answers[(string) $line->id] ?? [];
            $snapshot = new ProductionQualityPlanCheck([
                'check_type' => $line->check_type,
                'acceptance_rule' => $line->acceptance_rule,
                'minimum_value' => $line->minimum_value,
                'maximum_value' => $line->maximum_value,
                'target_value' => $line->target_value,
                'allowed_options' => $line->allowed_options,
                'required' => $line->is_required,
                'critical' => $line->is_critical,
            ]);
            $evaluation = $this->evaluateCheck($snapshot, $answer);
            $line->update([
                'numeric_value' => filled($answer['numeric_value'] ?? null) ? $answer['numeric_value'] : null,
                'boolean_value' => array_key_exists('boolean_value', $answer) && $answer['boolean_value'] !== '' ? $answer['boolean_value'] : null,
                'text_value' => filled($answer['text_value'] ?? null) ? $answer['text_value'] : null,
                'selected_value' => filled($answer['selected_value'] ?? null) ? $answer['selected_value'] : null,
                'result' => $evaluation,
                'inspector_comment' => filled($answer['inspector_comment'] ?? null) ? trim((string) $answer['inspector_comment']) : null,
            ]);
        }
    }

    private function requirementSnapshot(ProductionQualityPlanCheck $check): string
    {
        return collect([
            str($check->acceptance_rule)->headline()->toString(),
            $check->minimum_value !== null ? 'Min '.$check->minimum_value : null,
            $check->maximum_value !== null ? 'Max '.$check->maximum_value : null,
            $check->target_value !== null ? 'Target '.$check->target_value : null,
            filled($check->allowed_options) ? 'Options: '.implode(', ', (array) $check->allowed_options) : null,
        ])->filter()->implode(' · ');
    }

    private function applyQcDisposition(ProductionQualityInspection $inspection, ProductionCuringBatch $batch, User $user): void
    {
        $rejected = (string) ($inspection->failed_quantity ?? 0);
        if (bccomp($rejected, '0', 12) <= 0) {
            return;
        }
        $idempotencyKey = 'qc-rejection-inspection-'.$inspection->id;
        if (ProductionCuringAction::query()->where('company_id', $batch->company_id)->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }
        if (bccomp($rejected, (string) $batch->remaining_quantity, 12) > 0) {
            throw ValidationException::withMessages(['approval' => 'QC rejected quantity exceeds the batch quantity still available.']);
        }

        StockMovement::query()->where('product_id', $batch->product_id)
            ->where('stock_location_id', $batch->source_stock_location_id)->lockForUpdate()->get();
        $postingReference = 'QC-REJ-'.$inspection->inspection_number;
        ProductionCuringAction::query()->create([
            'company_id' => $batch->company_id,
            'production_curing_batch_id' => $batch->id,
            'action_type' => ProductionCuringAction::QC_REJECTION,
            'quantity' => $rejected,
            'reason' => collect([$inspection->disposition ? str($inspection->disposition)->headline() : null, $inspection->reason_justification])->filter()->implode(' · '),
            'posting_reference' => $postingReference,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $user->id,
        ]);
        StockMovement::query()->create([
            'company_id' => $batch->company_id,
            'branch_id' => $batch->branch_id,
            'product_id' => $batch->product_id,
            'stock_location_id' => $batch->source_stock_location_id,
            'source_location_id' => $batch->source_stock_location_id,
            'movement_type' => 'damage_out',
            'quantity' => $rejected,
            'quantity_in' => 0,
            'quantity_out' => $rejected,
            'reference_type' => ProductionOrder::class,
            'reference_id' => $batch->production_order_id,
            'production_curing_batch_id' => $batch->id,
            'posting_reference' => $postingReference,
            'notes' => 'QC rejection '.$inspection->inspection_number.' · '.str($inspection->disposition)->headline(),
            'created_by' => $user->id,
            'movement_date' => now()->toDateString(),
        ]);
        $batch->update([
            'qc_rejected_quantity' => bcadd((string) $batch->qc_rejected_quantity, $rejected, 12),
            'remaining_quantity' => bcsub((string) $batch->remaining_quantity, $rejected, 12),
            'updated_by' => $user->id,
        ]);
        $this->audit($inspection, 'disposition_recorded', $user, null, [
            'disposition' => $inspection->disposition,
            'qc_rejected_quantity' => $rejected,
            'posting_reference' => $postingReference,
        ], $inspection->reason_justification);
    }

    private function audit(ProductionQualityInspection $inspection, string $eventType, User $user, ?array $previous, ?array $new, ?string $reason = null): ProductionQualityAuditEvent
    {
        return ProductionQualityAuditEvent::query()->create([
            'company_id' => $inspection->company_id,
            'production_quality_inspection_id' => $inspection->id,
            'event_type' => $eventType,
            'reference_number' => $inspection->inspection_number,
            'previous_state' => $previous,
            'new_state' => $new,
            'reason' => $reason,
            'created_by' => $user->id,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $answer */
    public function evaluateCheck(ProductionQualityPlanCheck $check, array $answer): string
    {
        if (($answer['not_applicable'] ?? false) && ! $check->required) {
            return 'not_applicable';
        }
        $rule = $check->acceptance_rule;
        if ($rule === 'manual_judgement') {
            $manual = $answer['manual_result'] ?? null;

            return in_array($manual, ['passed', 'failed'], true) ? $manual : 'pending';
        }
        if (in_array($rule, ['yes_required', 'no_required'], true)) {
            if (! array_key_exists('boolean_value', $answer) || $answer['boolean_value'] === null || $answer['boolean_value'] === '') {
                return 'pending';
            }
            $actual = filter_var($answer['boolean_value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($actual === null) {
                return 'pending';
            }

            return ($rule === 'yes_required' ? $actual : ! $actual) ? 'passed' : 'failed';
        }
        if (! array_key_exists('numeric_value', $answer) || $answer['numeric_value'] === null || $answer['numeric_value'] === '') {
            return 'pending';
        }
        try {
            $actual = $this->calculator->decimal($answer['numeric_value']);
        } catch (\InvalidArgumentException) {
            return 'pending';
        }

        return match ($rule) {
            'within_range' => $check->minimum_value !== null && $check->maximum_value !== null && bccomp($actual, (string) $check->minimum_value, 8) >= 0 && bccomp($actual, (string) $check->maximum_value, 8) <= 0 ? 'passed' : 'failed',
            'minimum' => $check->minimum_value !== null && bccomp($actual, (string) $check->minimum_value, 8) >= 0 ? 'passed' : 'failed',
            'maximum' => $check->maximum_value !== null && bccomp($actual, (string) $check->maximum_value, 8) <= 0 ? 'passed' : 'failed',
            'equals' => $check->target_value !== null && bccomp($actual, (string) $check->target_value, 8) === 0 ? 'passed' : 'failed',
            default => 'pending',
        };
    }

    private function maintainAutomaticHold(ProductionQualityInspection $inspection, User $user): void
    {
        $exists = ProductionQualityHold::query()->where('company_id', $inspection->company_id)->active()
            ->when($inspection->production_curing_batch_id,
                fn ($q) => $q->where('production_curing_batch_id', $inspection->production_curing_batch_id),
                fn ($q) => $q->where('production_order_id', $inspection->production_order_id)->whereNull('production_curing_batch_id'))
            ->exists();
        if (! $exists) {
            $this->createHold(['production_order_id' => $inspection->production_order_id, 'production_curing_batch_id' => $inspection->production_curing_batch_id, 'production_quality_inspection_id' => $inspection->id, 'product_id' => $inspection->product_id, 'branch_id' => $inspection->branch_id, 'held_quantity' => $inspection->failed_quantity ?: $inspection->inspected_quantity, 'reason' => 'Automatic hold: critical quality check failed on '.$inspection->inspection_number.'.'], $user);
        }
    }

    private function createHold(array $data, User $user): ProductionQualityHold
    {
        $order = filled($data['production_order_id'] ?? null) ? ProductionOrder::query()->forCurrentCompany()->whereKey($data['production_order_id'])->firstOrFail() : null;
        $batch = filled($data['production_curing_batch_id'] ?? null) ? ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo($user)->whereKey($data['production_curing_batch_id'])->firstOrFail() : null;
        if (! $order && ! $batch) {
            throw ValidationException::withMessages(['context' => 'A production order or curing batch is required.']);
        }
        $context = $batch ?: $order;
        $this->assertBranchAccess($context->branch_id, $user);
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'A hold reason is required.']);
        }
        $inspectionId = $data['production_quality_inspection_id'] ?? null;
        if ($inspectionId) {
            $inspection = ProductionQualityInspection::query()->where('company_id', $context->company_id)->whereKey($inspectionId)->firstOrFail();
            if ((int) $inspection->product_id !== (int) $context->product_id
                || (int) ($inspection->production_order_id ?? 0) !== (int) ($order?->id ?? 0)
                || (int) ($inspection->production_curing_batch_id ?? 0) !== (int) ($batch?->id ?? 0)) {
                throw ValidationException::withMessages(['production_quality_inspection_id' => 'The inspection does not match the hold context.']);
            }
        }
        $heldQuantity = $data['held_quantity'] ?? null;
        if ($heldQuantity !== null && $heldQuantity !== '') {
            try {
                $heldQuantity = bcadd($this->calculator->decimal($heldQuantity), '0', 12);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['held_quantity' => 'Enter a valid held quantity.']);
            }
            $maximum = (string) ($batch?->remaining_quantity ?? $order?->accepted_quantity ?? $order?->planned_quantity);
            if (bccomp($heldQuantity, '0', 12) < 0 || bccomp($heldQuantity, $maximum, 12) > 0) {
                throw ValidationException::withMessages(['held_quantity' => 'Held quantity must be between zero and the applicable quantity.']);
            }
        }

        return ProductionQualityHold::query()->create([
            'company_id' => $context->company_id, 'branch_id' => $context->branch_id, 'production_order_id' => $order?->id,
            'production_curing_batch_id' => $batch?->id, 'production_quality_inspection_id' => $inspectionId,
            'product_id' => $context->product_id, 'hold_number' => $this->nextNumber($context->company_id, DocumentSequence::QUALITY_HOLD, 'QHL'),
            'reason' => trim($data['reason']), 'status' => 'active', 'held_quantity' => $heldQuantity,
            'placed_at' => now(), 'placed_by' => $user->id, 'notes' => $data['notes'] ?? null,
        ]);
    }

    private function validateQuantities(array $data, string $applicable): array
    {
        $values = [];
        foreach (['sample_quantity', 'inspected_quantity', 'passed_quantity', 'failed_quantity'] as $field) {
            $raw = $data[$field] ?? null;
            if ($raw === null || $raw === '') {
                $values[$field] = null;

                continue;
            }
            try {
                $value = bcadd($this->calculator->decimal($raw), '0', 12);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([$field => 'Enter a valid quantity.']);
            }
            if (bccomp($value, '0', 12) < 0) {
                throw ValidationException::withMessages([$field => 'Quantities cannot be negative.']);
            }
            $values[$field] = $value;
        }
        if ($values['sample_quantity'] !== null && bccomp($values['sample_quantity'], $applicable, 12) > 0) {
            throw ValidationException::withMessages(['sample_quantity' => 'Sample quantity cannot exceed the applicable quantity.']);
        }
        if ($values['inspected_quantity'] !== null && bccomp($values['inspected_quantity'], $applicable, 12) > 0) {
            throw ValidationException::withMessages(['inspected_quantity' => 'Inspected quantity cannot exceed the applicable quantity.']);
        }
        $sum = bcadd($values['passed_quantity'] ?? '0', $values['failed_quantity'] ?? '0', 12);
        if ($values['inspected_quantity'] !== null && bccomp($sum, $values['inspected_quantity'], 12) > 0) {
            throw ValidationException::withMessages(['passed_quantity' => 'Passed plus failed quantity cannot exceed inspected quantity.']);
        }

        return $values;
    }

    private function nextNumber(int $companyId, string $type, string $prefix, bool $withDate = false): string
    {
        $year = (int) now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore(['company_id' => $companyId, 'document_type' => $type, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = DocumentSequence::query()->where('company_id', $companyId)->where('document_type', $type)->where('year', $year)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return $prefix.'-'.($withDate ? now()->format('Ymd') : $year).'-'.str_pad((string) $sequence->fresh()->last_number, 4, '0', STR_PAD_LEFT);
    }

    private function authorize(User $user, string $permission): void
    {
        abort_unless($user->can($permission) && CompanyFeatures::manufacturingEnabled(), 403);
    }

    /** @param array<int, string> $permissions */
    private function authorizeAny(User $user, array $permissions): void
    {
        abort_unless($user->canAny($permissions) && CompanyFeatures::manufacturingEnabled(), 403);
    }

    private function sameCompany(int $companyId, User $user): void
    {
        abort_unless((int) $companyId === (int) $user->company_id, 404);
    }

    private function assertBranchAccess(?int $branchId, User $user): void
    {
        if ($user->branch_id && ! $user->can('manage cross branch stock locations') && $branchId !== null && (int) $branchId !== (int) $user->branch_id) {
            abort(404);
        }
    }
}
