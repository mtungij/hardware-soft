<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionCuringRelease;
use App\Models\ProductionOrder;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CompanyFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionCuringService
{
    public function __construct(
        private ProductionRecipeCalculator $calculator,
        private ProductionQualityService $quality,
    ) {}

    public function release(
        ProductionCuringBatch $batch,
        mixed $quantity,
        int $destinationLocationId,
        User $user,
        string $idempotencyKey,
        ?string $notes = null,
    ): ProductionCuringRelease {
        abort_unless($user->can('production.release_curing') && CompanyFeatures::manufacturingEnabled(), 403);
        $quantity = $this->positive($quantity, 'release_quantity');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($batch, $quantity, $destinationLocationId, $user, $idempotencyKey, $notes): ProductionCuringRelease {
            $batch = $this->lockedBatch($batch, $user);
            if ($existing = ProductionCuringRelease::query()->where('company_id', $batch->company_id)->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            if (bccomp((string) $batch->remaining_quantity, '0', 12) <= 0 || $batch->status === ProductionCuringBatch::STATUS_RELEASED) {
                throw ValidationException::withMessages(['release_quantity' => __('application.this_curing_batch_has_already_been_fully_released_5ef3c5d2')]);
            }
            if (! $batch->isEligibleForRelease()) {
                throw ValidationException::withMessages(['release_quantity' => $batch->status === ProductionCuringBatch::STATUS_QUARANTINED
                    ? 'Quarantined batches cannot be released.'
                    : 'This batch cannot be released before '.$batch->minimum_sellable_at->toDateString().'.']);
            }
            $this->quality->assertReleaseEligible($batch, $quantity);
            if (bccomp($quantity, (string) $batch->remaining_quantity, 12) > 0) {
                throw ValidationException::withMessages(['release_quantity' => __('application.release_quantity_cannot_exceed_the_remaining_curing_quantity_535ccd57')]);
            }

            $source = $this->lockedLocation($batch->source_stock_location_id, $batch, $user, false);
            $destination = $this->lockedLocation($destinationLocationId, $batch, $user, true);
            if ($source->id === $destination->id) {
                throw ValidationException::withMessages(['destination_stock_location_id' => __('application.release_destination_must_differ_from_the_curing_location_a7942364')]);
            }

            StockMovement::query()->where('product_id', $batch->product_id)->where('stock_location_id', $source->id)->lockForUpdate()->get();
            $ledgerQuantity = $this->ledgerQuantity($quantity);
            if (bccomp($this->availableStock($batch->product_id, $source->id), $ledgerQuantity, 4) < 0) {
                throw ValidationException::withMessages(['release_quantity' => __('application.the_curing_location_does_not_have_enough_stock_for_this_release_83e309a0')]);
            }

            $releaseNumber = $this->nextNumber($batch->company_id, DocumentSequence::CURING_RELEASE, 'CRL');
            $postingReference = 'CUR-REL-'.$releaseNumber;
            $release = ProductionCuringRelease::query()->create([
                'company_id' => $batch->company_id, 'production_curing_batch_id' => $batch->id,
                'release_number' => $releaseNumber, 'released_quantity' => $quantity,
                'source_stock_location_id' => $source->id, 'destination_stock_location_id' => $destination->id,
                'released_at' => now(), 'released_by' => $user->id, 'notes' => $notes,
                'posting_reference' => $postingReference, 'idempotency_key' => $idempotencyKey,
            ]);

            $movementBase = [
                'company_id' => $batch->company_id, 'branch_id' => $batch->branch_id,
                'product_id' => $batch->product_id, 'quantity' => $ledgerQuantity,
                'reference_type' => ProductionOrder::class, 'reference_id' => $batch->production_order_id,
                'production_curing_batch_id' => $batch->id, 'production_curing_release_id' => $release->id,
                'posting_reference' => $postingReference, 'notes' => $batch->batch_number.' / '.$postingReference,
                'created_by' => $user->id, 'movement_date' => now()->toDateString(),
            ];
            StockMovement::query()->create([
                ...$movementBase, 'stock_location_id' => $source->id, 'source_location_id' => $source->id,
                'destination_location_id' => $destination->id, 'movement_type' => 'curing_release_out',
                'quantity_in' => 0, 'quantity_out' => $ledgerQuantity,
            ]);
            StockMovement::query()->create([
                ...$movementBase, 'stock_location_id' => $destination->id, 'source_location_id' => $source->id,
                'destination_location_id' => $destination->id, 'movement_type' => 'curing_release_in',
                'quantity_in' => $ledgerQuantity, 'quantity_out' => 0,
            ]);

            $released = bcadd((string) $batch->released_quantity, $quantity, 12);
            $remaining = bcsub((string) $batch->remaining_quantity, $quantity, 12);
            $batch->update([
                'released_quantity' => $released, 'remaining_quantity' => $remaining,
                'release_eligible_quantity' => $batch->qc_approved_at
                    ? (bccomp(bcsub((string) $batch->release_eligible_quantity, $quantity, 12), '0', 12) < 0
                        ? '0' : bcsub((string) $batch->release_eligible_quantity, $quantity, 12))
                    : (string) $batch->release_eligible_quantity,
                'status' => bccomp($remaining, '0', 12) <= 0
                    ? ProductionCuringBatch::STATUS_RELEASED
                    : ProductionCuringBatch::STATUS_PARTIALLY_RELEASED,
                'updated_by' => $user->id,
            ]);

            return $release->load(['sourceLocation', 'destinationLocation']);
        }, 3);
    }

    public function quarantine(ProductionCuringBatch $batch, string $reason, User $user, string $idempotencyKey): ProductionCuringBatch
    {
        return $this->changeQuarantine($batch, true, $reason, $user, $idempotencyKey);
    }

    public function removeQuarantine(ProductionCuringBatch $batch, string $reason, User $user, string $idempotencyKey): ProductionCuringBatch
    {
        return $this->changeQuarantine($batch, false, $reason, $user, $idempotencyKey);
    }

    public function recordDamage(ProductionCuringBatch $batch, mixed $quantity, string $reason, User $user, string $idempotencyKey): ProductionCuringBatch
    {
        abort_unless($user->can('production.manage_curing') && CompanyFeatures::manufacturingEnabled(), 403);
        $quantity = $this->positive($quantity, 'damage_quantity');
        $reason = $this->reason($reason);
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($batch, $quantity, $reason, $user, $idempotencyKey): ProductionCuringBatch {
            $batch = $this->lockedBatch($batch, $user);
            if (ProductionCuringAction::query()->where('company_id', $batch->company_id)->where('idempotency_key', $idempotencyKey)->exists()) {
                return $batch;
            }
            if (in_array($batch->status, [ProductionCuringBatch::STATUS_RELEASED, ProductionCuringBatch::STATUS_CLOSED], true)
                || bccomp($quantity, (string) $batch->remaining_quantity, 12) > 0) {
                throw ValidationException::withMessages(['damage_quantity' => __('application.damage_quantity_cannot_exceed_the_remaining_curing_quantity_a1ed186d')]);
            }
            $source = $this->lockedLocation($batch->source_stock_location_id, $batch, $user, false);
            StockMovement::query()->where('product_id', $batch->product_id)->where('stock_location_id', $source->id)->lockForUpdate()->get();
            $ledgerQuantity = $this->ledgerQuantity($quantity);
            if (bccomp($this->availableStock($batch->product_id, $source->id), $ledgerQuantity, 4) < 0) {
                throw ValidationException::withMessages(['damage_quantity' => __('application.the_curing_location_does_not_have_enough_stock_840caf9b')]);
            }
            $postingReference = 'CUR-DMG-'.$batch->id.'-'.substr($idempotencyKey, 0, 12);
            ProductionCuringAction::query()->create([
                'company_id' => $batch->company_id, 'production_curing_batch_id' => $batch->id,
                'action_type' => ProductionCuringAction::DAMAGE, 'quantity' => $quantity, 'reason' => $reason,
                'posting_reference' => $postingReference, 'idempotency_key' => $idempotencyKey, 'created_by' => $user->id,
            ]);
            StockMovement::query()->create([
                'company_id' => $batch->company_id, 'branch_id' => $batch->branch_id,
                'product_id' => $batch->product_id, 'stock_location_id' => $source->id,
                'source_location_id' => $source->id, 'movement_type' => 'curing_damage',
                'quantity' => $ledgerQuantity, 'quantity_in' => 0, 'quantity_out' => $ledgerQuantity,
                'reference_type' => ProductionOrder::class, 'reference_id' => $batch->production_order_id,
                'production_curing_batch_id' => $batch->id, 'posting_reference' => $postingReference,
                'notes' => $batch->batch_number.' / '.$reason, 'created_by' => $user->id,
                'movement_date' => now()->toDateString(),
            ]);
            $remaining = bcsub((string) $batch->remaining_quantity, $quantity, 12);
            $batch->update([
                'damaged_quantity' => bcadd((string) $batch->damaged_quantity, $quantity, 12),
                'remaining_quantity' => $remaining,
                'release_eligible_quantity' => bccomp((string) $batch->release_eligible_quantity, '0', 12) > 0
                    ? (bccomp(bcsub((string) $batch->release_eligible_quantity, $quantity, 12), '0', 12) < 0
                        ? '0' : bcsub((string) $batch->release_eligible_quantity, $quantity, 12))
                    : '0',
                'status' => bccomp($remaining, '0', 12) <= 0 ? ProductionCuringBatch::STATUS_CLOSED : $batch->status,
                'closed_at' => bccomp($remaining, '0', 12) <= 0 ? now() : null,
                'closed_by' => bccomp($remaining, '0', 12) <= 0 ? $user->id : null,
                'updated_by' => $user->id,
            ]);

            return $batch->refresh();
        }, 3);
    }

    private function changeQuarantine(ProductionCuringBatch $batch, bool $quarantine, string $reason, User $user, string $idempotencyKey): ProductionCuringBatch
    {
        abort_unless($user->can('production.manage_curing') && CompanyFeatures::manufacturingEnabled(), 403);
        $reason = $this->reason($reason);
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($batch, $quarantine, $reason, $user, $idempotencyKey): ProductionCuringBatch {
            $batch = $this->lockedBatch($batch, $user);
            if (ProductionCuringAction::query()->where('company_id', $batch->company_id)->where('idempotency_key', $idempotencyKey)->exists()) {
                return $batch;
            }
            if (in_array($batch->status, [ProductionCuringBatch::STATUS_RELEASED, ProductionCuringBatch::STATUS_CLOSED], true)) {
                throw ValidationException::withMessages(['status' => __('application.a_released_or_closed_batch_cannot_be_quarantined_72d7d23a')]);
            }
            if ($quarantine === ($batch->status === ProductionCuringBatch::STATUS_QUARANTINED)) {
                throw ValidationException::withMessages(['status' => $quarantine ? 'Batch is already quarantined.' : 'Batch is not quarantined.']);
            }
            if (! $quarantine) {
                $this->quality->assertNoActiveHold($batch);
            }
            ProductionCuringAction::query()->create([
                'company_id' => $batch->company_id, 'production_curing_batch_id' => $batch->id,
                'action_type' => $quarantine ? ProductionCuringAction::QUARANTINE : ProductionCuringAction::UNQUARANTINE,
                'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'created_by' => $user->id,
            ]);
            $normalStatus = bccomp((string) $batch->remaining_quantity, '0', 12) <= 0
                ? ProductionCuringBatch::STATUS_RELEASED
                : (bccomp((string) $batch->released_quantity, '0', 12) > 0
                    ? ProductionCuringBatch::STATUS_PARTIALLY_RELEASED
                    : ($batch->qc_approved_at
                        ? ProductionCuringBatch::STATUS_READY_FOR_RELEASE
                        : (now($batch->company?->timezone ?: config('app.timezone'))->gte($batch->minimum_sellable_at)
                        ? ProductionCuringBatch::STATUS_ELIGIBLE
                        : ProductionCuringBatch::STATUS_CURING)));
            $batch->update([
                'status' => $quarantine ? ProductionCuringBatch::STATUS_QUARANTINED : $normalStatus,
                'quarantine_reason' => $quarantine ? $reason : null, 'updated_by' => $user->id,
            ]);

            return $batch->refresh();
        });
    }

    private function lockedBatch(ProductionCuringBatch $batch, User $user): ProductionCuringBatch
    {
        abort_unless((int) $batch->company_id === (int) $user->company_id, 404);

        return ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo($user)->whereKey($batch->id)->with(['company', 'product'])->lockForUpdate()->firstOrFail();
    }

    private function lockedLocation(int $id, ProductionCuringBatch $batch, User $user, bool $sellable): StockLocation
    {
        $location = StockLocation::query()->where('company_id', $batch->company_id)->whereKey($id)->lockForUpdate()->first();
        if (! $location || ! $location->isActive() || (int) $location->branch_id !== (int) $batch->branch_id
            || (bool) $location->is_sellable !== $sellable
            || ($sellable && (! $location->can_receive_stock || ! $location->can_sell))
            || (! $sellable && (! $location->can_issue_stock || ! in_array($location->type, ['curing', 'quarantine'], true)))) {
            throw ValidationException::withMessages(['destination_stock_location_id' => __('application.select_a_valid_branch_compatible_stock_location_a296e6f6')]);
        }
        if ($user->stockLocations()->exists()) {
            $ability = $sellable ? 'can_receive' : 'can_transfer';
            if (! $user->stockLocations()->where('stock_locations.id', $location->id)->wherePivot($ability, true)->exists()) {
                throw ValidationException::withMessages(['location' => __('application.you_are_not_authorised_for_this_stock_location_9aef2cb1')]);
            }
        }

        return $location;
    }

    private function nextNumber(int $companyId, string $type, string $prefix): string
    {
        $year = (int) now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore([
            'company_id' => $companyId, 'document_type' => $type, 'year' => $year,
            'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DocumentSequence::query()->where('company_id', $companyId)->where('document_type', $type)
            ->where('year', $year)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return $prefix.'-'.$year.'-'.str_pad((string) $sequence->fresh()->last_number, 4, '0', STR_PAD_LEFT);
    }

    private function availableStock(int $productId, int $locationId): string
    {
        $negative = implode(',', array_fill(0, count(StockMovement::NEGATIVE_TYPES), '?'));
        $value = StockMovement::query()->where('product_id', $productId)->where('stock_location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN quantity_in <> 0 OR quantity_out <> 0 THEN quantity_in - quantity_out WHEN movement_type IN ({$negative}) THEN -quantity ELSE quantity END), 0) available", StockMovement::NEGATIVE_TYPES)
            ->value('available');

        return bcadd((string) ($value ?? 0), '0', 4);
    }

    private function positive(mixed $value, string $field): string
    {
        try {
            $number = $this->calculator->decimal($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('application.enter_a_valid_quantity_ef29ff31')]);
        }
        if (bccomp($number, '0', 12) <= 0) {
            throw ValidationException::withMessages([$field => __('application.quantity_must_be_greater_than_zero_a53d797f')]);
        }

        return bcadd($number, '0', 12);
    }

    private function ledgerQuantity(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    private function reason(string $reason): string
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('application.a_reason_is_required_6b13e944')]);
        }

        return trim($reason);
    }

    private function idempotencyKey(string $key): string
    {
        return substr(trim($key) ?: (string) str()->uuid(), 0, 100);
    }
}
