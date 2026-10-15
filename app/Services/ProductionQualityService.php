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
use App\Support\UiText;
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
                throw ValidationException::withMessages(['plan' => __('application.only_a_draft_plan_with_checks_for_a_manufactured_product_can_be_activated_c4719768')]);
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
            throw ValidationException::withMessages(['plan' => __('application.only_an_active_plan_can_be_deactivated_40396279')]);
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
                'notes' => __('application.automatically_queued_when_production_completed_2d035ae8'),
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
                throw ValidationException::withMessages(['inspection' => __('application.only_a_queued_curing_inspection_may_be_recorded_fd841482')]);
            }

            $result = (string) ($data['result'] ?? '');
            if (! in_array($result, ['passed', 'conditional', 'failed', 'hold'], true)) {
                throw ValidationException::withMessages(['result' => __('application.select_pass_conditional_pass_fail_or_hold_0a87e633')]);
            }
            $batch = ProductionCuringBatch::query()->where('company_id', $inspection->company_id)
                ->whereKey($inspection->production_curing_batch_id)->lockForUpdate()->firstOrFail();
            if (! $inspection->production_quality_plan_id || ! $inspection->plan) {
                throw ValidationException::withMessages(['plan' => __('application.no_active_qc_plan_is_assigned_to_this_product_or_product_family_ccbe7890')]);
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
                throw ValidationException::withMessages(['accepted_quantity' => __('application.qc_accepted_plus_qc_rejected_quantity_must_equal_the_curing_quantity_curren_bdb40341')]);
            }
            $reason = trim((string) ($data['reason_justification'] ?? ''));
            $correctiveAction = trim((string) ($data['corrective_action'] ?? ''));
            $disposition = filled($data['disposition'] ?? null) ? (string) $data['disposition'] : null;
            $retestRequired = (bool) ($data['retest_required'] ?? false);
            $retestDate = filled($data['retest_date'] ?? null) ? (string) $data['retest_date'] : null;
            if (in_array($result, ['conditional', 'failed', 'hold'], true)) {
                if ($reason === '') {
                    throw ValidationException::withMessages(['reason_justification' => __('application.reason_justification_is_required_for_this_qc_decision_48dd54f9')]);
                }
                if ($correctiveAction === '') {
                    throw ValidationException::withMessages(['corrective_action' => __('application.corrective_action_is_required_for_this_qc_decision_d7ff31b8')]);
                }
                if (! in_array($disposition, ProductionQualityInspection::DISPOSITIONS, true)) {
                    throw ValidationException::withMessages(['disposition' => __('application.select_a_valid_disposition_a69da5b8')]);
                }
            }
            if (bccomp($rejected, '0', 12) > 0 && ! in_array($disposition, ProductionQualityInspection::DISPOSITIONS, true)) {
                throw ValidationException::withMessages(['disposition' => __('application.a_disposition_is_required_when_qc_rejects_quantity_80ab9eaf')]);
            }
            if ($retestRequired && ! $retestDate) {
                throw ValidationException::withMessages(['retest_date' => __('application.retest_date_is_required_when_a_retest_is_requested_48cbe5fa')]);
            }

            $inspector = User::query()->where('company_id', $inspection->company_id)
                ->where('status', 'active')->findOrFail((int) ($data['inspector_id'] ?? 0));
            abort_unless($inspector->can('production.perform_quality_inspections'), 422);
            $this->assertBranchAccess($inspection->branch_id, $inspector);

            $this->recordChecklistAnswers($inspection, (array) ($data['check_answers'] ?? []));
            $criticalFailed = $inspection->results()->where('is_critical', true)->where('result', 'failed')->exists();
            if ($result === 'passed' && $criticalFailed) {
                throw ValidationException::withMessages(['result' => __('application.a_failed_critical_check_prevents_a_final_pass_decision_98f7ec2c')]);
            }
            if (in_array($result, ['failed', 'hold'], true) && $inspection->plan->requires_failure_evidence && $inspection->attachments->isEmpty()) {
                throw ValidationException::withMessages(['evidence' => __('application.this_qc_plan_requires_evidence_for_a_fail_or_hold_decision_3c1025be')]);
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
                throw ValidationException::withMessages(['context' => __('application.select_exactly_one_production_order_or_curing_batch_1d2fc93e')]);
            }
            $context = $batch ?: $order;
            $this->assertBranchAccess($context->branch_id, $user);
            $stage = (string) ($data['inspection_stage'] ?? '');
            $allowed = $batch ? ['curing', 'pre_release'] : ['raw_material', 'during_production', 'production_completion'];
            if (! in_array($stage, $allowed, true)) {
                throw ValidationException::withMessages(['inspection_stage' => __('application.the_inspection_stage_does_not_match_the_selected_context_2eb51417')]);
            }
            $product = Product::query()->where('company_id', $context->company_id)->whereKey($context->product_id)->firstOrFail();
            if (! $product->isManufactured()) {
                throw ValidationException::withMessages(['product_id' => __('application.quality_inspections_are_available_only_for_manufactured_products_33148eed')]);
            }
            $plan = ProductionQualityPlan::query()->where('company_id', $context->company_id)->where('product_id', $product->id)
                ->where('inspection_stage', $stage)->where('status', 'active')->with('checks')->lockForUpdate()->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan' => __('application.no_active_quality_plan_exists_for_this_product_and_stage_dfc00d20')]);
            }
            $supersedesId = $data['supersedes_inspection_id'] ?? null;
            if ($supersedesId) {
                $previous = ProductionQualityInspection::query()->where('company_id', $context->company_id)->whereKey($supersedesId)->lockForUpdate()->firstOrFail();
                if (! in_array($previous->result, ['failed', 'conditional'], true)
                    || (int) $previous->product_id !== (int) $product->id
                    || (int) ($previous->production_order_id ?? 0) !== (int) ($order?->id ?? 0)
                    || (int) ($previous->production_curing_batch_id ?? 0) !== (int) ($batch?->id ?? 0)
                    || $previous->inspection_stage !== $stage) {
                    throw ValidationException::withMessages(['supersedes_inspection_id' => __('application.a_retest_must_reference_a_failed_or_conditional_inspection_for_the_same_con_cfe9d41b')]);
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
                $updates += ['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id, 'approval_reason' => __('application.plan_does_not_require_separate_approval_37aa07bb')];
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
                throw ValidationException::withMessages(['approval' => __('application.only_a_completed_pass_or_conditional_pass_inspection_may_be_approved_fail_a_50872ad8')]);
            }
            if ($inspection->result === 'conditional' && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['approval_reason' => __('application.a_justification_is_required_to_approve_a_conditional_result_6fb5d973')]);
            }
            if ($inspection->results->where('is_critical', true)->where('result', 'pending')->isNotEmpty()) {
                throw ValidationException::withMessages(['approval' => __('application.pending_critical_checks_must_be_completed_before_approval_56d6f898')]);
            }
            if ($inspection->results->where('is_critical', true)->where('result', 'failed')->isNotEmpty()) {
                throw ValidationException::withMessages(['approval' => __('application.a_failed_critical_check_cannot_be_approved_for_release_cc53a780')]);
            }
            if ($inspection->plan?->enforce_approval_separation && (int) $inspection->inspected_by === (int) $user->id) {
                if (! $user->can('production.override_qc_separation')) {
                    throw ValidationException::withMessages(['approval' => __('application.the_inspector_cannot_approve_their_own_inspection_while_segregation_of_duti_9137ad65')]);
                }
                if (trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['approval_reason' => __('application.an_audit_reason_is_required_to_override_inspector_approver_separation_33641cca')]);
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
                            'release_reason' => __('feedback.approved_retest', ['number' => $inspection->inspection_number]),
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
            throw ValidationException::withMessages(['rejection_reason' => __('application.a_rejection_reason_is_required_462ed53e')]);
        }

        return DB::transaction(function () use ($inspection, $user, $reason): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($inspection->approval_status === 'rejected') {
                return $inspection;
            }
            if ($inspection->approval_status !== 'pending') {
                throw ValidationException::withMessages(['approval' => __('application.this_inspection_has_already_been_decided_4f4f969c')]);
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
            throw ValidationException::withMessages(['release_reason' => __('application.a_release_reason_is_required_fe1c6d1a')]);
        }

        return DB::transaction(function () use ($hold, $user, $reason): ProductionQualityHold {
            $hold = ProductionQualityHold::query()->forCurrentCompany()->accessibleTo($user)->whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($hold->status !== 'active') {
                throw ValidationException::withMessages(['hold' => __('application.only_an_active_hold_can_be_released_138014c3')]);
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
            throw ValidationException::withMessages(['release_quantity' => __('application.release_quantity_exceeds_the_quantity_approved_by_quality_inspection_9d7cbb38')]);
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
            throw ValidationException::withMessages(['evidence_category' => __('application.select_a_valid_evidence_category_26bff16d')]);
        }
        $allowed = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (! in_array((string) $file->getMimeType(), $allowed, true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['evidence' => __('application.evidence_must_be_a_jpg_png_pdf_word_or_excel_file_no_larger_than_10_mb_97a77803')]);
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
            UiText::label($check->acceptance_rule),
            $check->minimum_value !== null ? __('feedback.minimum_value', ['value' => $check->minimum_value]) : null,
            $check->maximum_value !== null ? __('feedback.maximum_value', ['value' => $check->maximum_value]) : null,
            $check->target_value !== null ? __('feedback.target_value', ['value' => $check->target_value]) : null,
            filled($check->allowed_options) ? __('feedback.options_value', ['value' => implode(', ', (array) $check->allowed_options)]) : null,
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
            throw ValidationException::withMessages(['approval' => __('application.qc_rejected_quantity_exceeds_the_batch_quantity_still_available_99a3339d')]);
        }

        StockMovement::query()->where('product_id', $batch->product_id)
            ->where('stock_location_id', $batch->source_stock_location_id)->lockForUpdate()->get();
        $postingReference = 'QC-REJ-'.$inspection->inspection_number;
        ProductionCuringAction::query()->create([
            'company_id' => $batch->company_id,
            'production_curing_batch_id' => $batch->id,
            'action_type' => ProductionCuringAction::QC_REJECTION,
            'quantity' => $rejected,
            'reason' => collect([$inspection->disposition ? UiText::label($inspection->disposition) : null, $inspection->reason_justification])->filter()->implode(' · '),
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
            'notes' => __('feedback.quality_rejection', ['number' => $inspection->inspection_number, 'disposition' => UiText::label($inspection->disposition)]),
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
            $this->createHold(['production_order_id' => $inspection->production_order_id, 'production_curing_batch_id' => $inspection->production_curing_batch_id, 'production_quality_inspection_id' => $inspection->id, 'product_id' => $inspection->product_id, 'branch_id' => $inspection->branch_id, 'held_quantity' => $inspection->failed_quantity ?: $inspection->inspected_quantity, 'reason' => __('feedback.critical_hold', ['number' => $inspection->inspection_number])], $user);
        }
    }

    private function createHold(array $data, User $user): ProductionQualityHold
    {
        $order = filled($data['production_order_id'] ?? null) ? ProductionOrder::query()->forCurrentCompany()->whereKey($data['production_order_id'])->firstOrFail() : null;
        $batch = filled($data['production_curing_batch_id'] ?? null) ? ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo($user)->whereKey($data['production_curing_batch_id'])->firstOrFail() : null;
        if (! $order && ! $batch) {
            throw ValidationException::withMessages(['context' => __('application.a_production_order_or_curing_batch_is_required_b0983767')]);
        }
        $context = $batch ?: $order;
        $this->assertBranchAccess($context->branch_id, $user);
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => __('application.a_hold_reason_is_required_bb258d50')]);
        }
        $inspectionId = $data['production_quality_inspection_id'] ?? null;
        if ($inspectionId) {
            $inspection = ProductionQualityInspection::query()->where('company_id', $context->company_id)->whereKey($inspectionId)->firstOrFail();
            if ((int) $inspection->product_id !== (int) $context->product_id
                || (int) ($inspection->production_order_id ?? 0) !== (int) ($order?->id ?? 0)
                || (int) ($inspection->production_curing_batch_id ?? 0) !== (int) ($batch?->id ?? 0)) {
                throw ValidationException::withMessages(['production_quality_inspection_id' => __('application.the_inspection_does_not_match_the_hold_context_0514f95a')]);
            }
        }
        $heldQuantity = $data['held_quantity'] ?? null;
        if ($heldQuantity !== null && $heldQuantity !== '') {
            try {
                $heldQuantity = bcadd($this->calculator->decimal($heldQuantity), '0', 12);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['held_quantity' => __('application.enter_a_valid_held_quantity_f86de228')]);
            }
            $maximum = (string) ($batch?->remaining_quantity ?? $order?->accepted_quantity ?? $order?->planned_quantity);
            if (bccomp($heldQuantity, '0', 12) < 0 || bccomp($heldQuantity, $maximum, 12) > 0) {
                throw ValidationException::withMessages(['held_quantity' => __('application.held_quantity_must_be_between_zero_and_the_applicable_quantity_fe9f73b2')]);
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
                throw ValidationException::withMessages([$field => __('application.enter_a_valid_quantity_ef29ff31')]);
            }
            if (bccomp($value, '0', 12) < 0) {
                throw ValidationException::withMessages([$field => __('application.quantities_cannot_be_negative_9654edd6')]);
            }
            $values[$field] = $value;
        }
        if ($values['sample_quantity'] !== null && bccomp($values['sample_quantity'], $applicable, 12) > 0) {
            throw ValidationException::withMessages(['sample_quantity' => __('application.sample_quantity_cannot_exceed_the_applicable_quantity_f0ad99b8')]);
        }
        if ($values['inspected_quantity'] !== null && bccomp($values['inspected_quantity'], $applicable, 12) > 0) {
            throw ValidationException::withMessages(['inspected_quantity' => __('application.inspected_quantity_cannot_exceed_the_applicable_quantity_75f5b2af')]);
        }
        $sum = bcadd($values['passed_quantity'] ?? '0', $values['failed_quantity'] ?? '0', 12);
        if ($values['inspected_quantity'] !== null && bccomp($sum, $values['inspected_quantity'], 12) > 0) {
            throw ValidationException::withMessages(['passed_quantity' => __('application.passed_plus_failed_quantity_cannot_exceed_inspected_quantity_f48a5171')]);
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
