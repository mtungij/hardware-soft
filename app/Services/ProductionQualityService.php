<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionQualityHold;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionQualityPlan;
use App\Models\ProductionQualityPlanCheck;
use App\Models\User;
use App\Support\CompanyFeatures;
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
                ->first();

            return ProductionQualityInspection::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'production_quality_plan_id' => $plan?->id,
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
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordQueuedInspection(ProductionQualityInspection $inspection, array $data, User $user): ProductionQualityInspection
    {
        $this->authorize($user, 'production.perform_quality_inspections');

        return DB::transaction(function () use ($inspection, $data, $user): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)
                ->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($inspection->result !== 'pending' || ! $inspection->production_curing_batch_id) {
                throw ValidationException::withMessages(['inspection' => 'Only a queued curing inspection may be recorded.']);
            }

            $result = (string) ($data['result'] ?? '');
            if (! in_array($result, ['passed', 'conditional', 'failed'], true)) {
                throw ValidationException::withMessages(['result' => 'Select Pass, Partial Pass, or Fail.']);
            }
            $quantities = $this->validateQuantities([
                'inspected_quantity' => $inspection->applicable_quantity,
                'passed_quantity' => $data['accepted_quantity'] ?? null,
                'failed_quantity' => $data['rejected_quantity'] ?? null,
            ], (string) $inspection->applicable_quantity);
            $accepted = $quantities['passed_quantity'] ?? '0';
            $rejected = $quantities['failed_quantity'] ?? '0';
            if (bccomp(bcadd($accepted, $rejected, 12), (string) $inspection->applicable_quantity, 12) !== 0) {
                throw ValidationException::withMessages(['accepted_quantity' => 'Accepted plus rejected quantity must equal the production accepted quantity.']);
            }
            if (($result === 'passed' && bccomp($rejected, '0', 12) !== 0)
                || ($result === 'failed' && bccomp($accepted, '0', 12) !== 0)
                || ($result === 'conditional' && (bccomp($accepted, '0', 12) <= 0 || bccomp($rejected, '0', 12) <= 0))) {
                throw ValidationException::withMessages(['result' => 'The decision must match the accepted and rejected quantities.']);
            }

            $inspector = User::query()->where('company_id', $inspection->company_id)
                ->where('status', 'active')->findOrFail((int) ($data['inspector_id'] ?? 0));
            abort_unless($inspector->can('production.perform_quality_inspections'), 422);
            $this->assertBranchAccess($inspection->branch_id, $inspector);

            $inspection->update([
                'inspected_quantity' => $inspection->applicable_quantity,
                'passed_quantity' => $accepted,
                'failed_quantity' => $rejected,
                'result' => $result,
                'inspected_at' => now(),
                'inspected_by' => $inspector->id,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            ]);

            return $inspection->refresh();
        }, 3);
    }

    public function duplicatePlan(ProductionQualityPlan $source, User $user): ProductionQualityPlan
    {
        $this->authorize($user, 'production.manage_quality_plans');
        $this->sameCompany($source->company_id, $user);

        return DB::transaction(function () use ($source, $user): ProductionQualityPlan {
            $source->loadMissing('checks');
            $copy = ProductionQualityPlan::query()->create([
                ...$source->only(['company_id', 'product_id', 'name', 'code', 'version', 'inspection_stage', 'effective_from', 'effective_to', 'requires_approval', 'notes']),
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
                'production_quality_plan_id' => $plan->id, 'production_order_id' => $order?->id,
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
                    'check_name' => $check->name, 'check_type' => $check->check_type, 'acceptance_rule' => $check->acceptance_rule,
                    'unit_id' => $check->unit_id, 'minimum_value' => $check->minimum_value, 'maximum_value' => $check->maximum_value,
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

            return $inspection->load(['plan', 'results.unit', 'holds']);
        }, 3);
    }

    public function approve(ProductionQualityInspection $inspection, User $user, ?string $reason = null): ProductionQualityInspection
    {
        $this->authorize($user, 'production.approve_quality');

        return DB::transaction(function () use ($inspection, $user, $reason): ProductionQualityInspection {
            $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($inspection->approval_status !== 'pending' || $inspection->result === 'pending' || $inspection->result === 'failed') {
                throw ValidationException::withMessages(['approval' => 'Only a completed passed or conditional inspection may be approved. Failed inspections require a retest.']);
            }
            if ($inspection->result === 'conditional' && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['approval_reason' => 'A justification is required to approve a conditional result.']);
            }
            $inspection->update(['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id, 'approval_reason' => $reason]);

            if ($inspection->production_curing_batch_id) {
                $batch = ProductionCuringBatch::query()->where('company_id', $inspection->company_id)
                    ->whereKey($inspection->production_curing_batch_id)->lockForUpdate()->firstOrFail();
                if (! in_array($batch->status, [ProductionCuringBatch::STATUS_RELEASED, ProductionCuringBatch::STATUS_CLOSED, ProductionCuringBatch::STATUS_QUARANTINED], true)) {
                    $batch->update([
                        'status' => ProductionCuringBatch::STATUS_READY_FOR_RELEASE,
                        'qc_approved_at' => now(),
                        'approved_by' => $user->id,
                        'updated_by' => $user->id,
                    ]);
                }
            }

            return $inspection->refresh();
        });
    }

    public function reject(ProductionQualityInspection $inspection, User $user, string $reason): ProductionQualityInspection
    {
        $this->authorize($user, 'production.approve_quality');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection reason is required.']);
        }
        $inspection = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo($user)->whereKey($inspection->id)->firstOrFail();
        if ($inspection->approval_status !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'This inspection has already been decided.']);
        }
        $inspection->update(['approval_status' => 'rejected', 'approved_at' => now(), 'approved_by' => $user->id, 'rejection_reason' => trim($reason)]);

        return $inspection->refresh();
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
        if ($quantity !== null && $approved->passed_quantity !== null && bccomp(bcadd((string) $batch->released_quantity, $quantity, 12), (string) $approved->passed_quantity, 12) > 0) {
            throw ValidationException::withMessages(['release_quantity' => 'Release quantity exceeds the quantity approved by quality inspection.']);
        }
    }

    public function assertNoActiveHold(ProductionCuringBatch $batch): void
    {
        if (ProductionQualityHold::query()->where('company_id', $batch->company_id)->where('production_curing_batch_id', $batch->id)->active()->exists()) {
            throw ValidationException::withMessages(['release_quantity' => __('production.quality.active_hold_blocks_release')]);
        }
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
