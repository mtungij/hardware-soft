<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionScheduleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $user, ?ProductionMachineAssignment $assignment = null): ProductionMachineAssignment
    {
        $companyId = (int) $user->company_id;

        if (! $companyId || ($assignment && (int) $assignment->company_id !== $companyId)) {
            abort(404);
        }

        if ($assignment?->status === ProductionMachineAssignment::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => __('production.validation.completed_read_only'),
            ]);
        }

        if ($assignment?->immutableProductionOrder()) {
            throw ValidationException::withMessages([
                'status' => 'This historical assignment is linked to a production order and cannot be changed. Create a new assignment.',
            ]);
        }

        if (($data['status'] ?? null) === ProductionMachineAssignment::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => __('production.validation.complete_via_action'),
            ]);
        }

        $product = Product::query()
            ->with('productFamily')
            ->where('company_id', $companyId)
            ->whereKey($data['product_id'])
            ->first();

        if (! $product || ! $product->isManufactured()) {
            throw ValidationException::withMessages(['product_id' => __('production.validation.manufactured_only')]);
        }
        $method = $product->productFamily?->production_method ?: ProductFamily::METHOD_MACHINE_MOULD;
        if (! in_array($method, ProductFamily::PRODUCTION_METHODS, true)) {
            throw ValidationException::withMessages(['product_id' => 'The product family has an invalid production method.']);
        }

        $machine = null;
        $installation = null;
        if ($method === ProductFamily::METHOD_MACHINE_MOULD) {
            $machine = Machine::query()->forCurrentCompany()->whereKey($data['machine_id'] ?? null)->first();
            if (! $machine) {
                throw ValidationException::withMessages(['machine_id' => __('production.validation.invalid_machine')]);
            }
            if ($machine->status !== Machine::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['machine_id' => __('production.validation.machine_not_active')]);
            }
            $installation = $machine->currentMouldInstallation()->with('mould')->first();
            $mould = $installation?->mould;
            if (! $mould) {
                throw ValidationException::withMessages(['machine_id' => __('production.validation.mould_required')]);
            }
            if (! $mould->compatibleMachines()->whereKey($machine->id)->exists()) {
                throw ValidationException::withMessages(['machine_id' => __('production.moulds.validation.not_compatible')]);
            }
        } else {
            if (filled($data['machine_id'] ?? null)) {
                throw ValidationException::withMessages(['machine_id' => 'A mould-only schedule must not be converted into machine production.']);
            }
            $mould = ProductionMould::query()->forCurrentCompany()->available()->whereKey($data['production_mould_id'] ?? null)->first();
            if (! $mould) {
                throw ValidationException::withMessages(['production_mould_id' => __('production.validation.mould_required')]);
            }
        }
        if (! $mould->active || $mould->under_maintenance) {
            throw ValidationException::withMessages(['production_mould_id' => __('production.validation.mould_unavailable')]);
        }
        if (! $product->product_family_id || (int) $product->product_family_id !== (int) $mould->product_family_id) {
            throw ValidationException::withMessages(['product_id' => __('production.validation.product_mould_incompatible')]);
        }

        $recipe = ProductionRecipe::query()->forCurrentCompany()
            ->where('product_id', $product->id)
            ->where('status', ProductionRecipe::STATUS_ACTIVE)
            ->when(filled($data['production_recipe_id'] ?? null), fn ($query) => $query->whereKey($data['production_recipe_id']))
            ->first();
        if (! $recipe) {
            throw ValidationException::withMessages(['production_recipe_id' => __('production.validation.active_recipe_required')]);
        }

        $branchId = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;

        if ($branchId && ! Branch::query()->where('company_id', $companyId)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('production.validation.invalid_branch')]);
        }

        if ($machine?->branch_id && $branchId && (int) $machine->branch_id !== $branchId) {
            throw ValidationException::withMessages(['branch_id' => __('production.validation.branch_mismatch')]);
        }

        $blocksSlot = in_array($data['status'], ProductionMachineAssignment::BLOCKING_STATUSES, true);
        $duplicate = $blocksSlot
            ? ProductionMachineAssignment::query()
                ->forCurrentCompany()
                ->blocking()
                ->where($method === ProductFamily::METHOD_MACHINE_MOULD ? 'machine_id' : 'production_mould_id', $method === ProductFamily::METHOD_MACHINE_MOULD ? $machine->id : $mould->id)
                ->whereDate('production_date', $data['production_date'])
                ->when($assignment, fn ($query) => $query->whereKeyNot($assignment->id))
                ->with('product')
                ->first()
            : null;

        if ($duplicate) {
            throw ValidationException::withMessages([
                ($method === ProductFamily::METHOD_MACHINE_MOULD ? 'machine_id' : 'production_mould_id') => __('production.validation.duplicate', [
                    'product' => $duplicate->product?->name ?: __('production.unknown_product'),
                    'date' => $data['production_date'],
                ]),
            ]);
        }

        $values = [
            ...$data,
            'company_id' => $companyId,
            'production_method' => $method,
            'machine_id' => $machine?->id,
            'production_mould_id' => $mould->id,
            'production_mould_installation_id' => $installation?->id,
            'production_recipe_id' => $recipe->id,
            'branch_id' => $branchId,
            'updated_by' => $user->id,
        ];

        try {
            return DB::transaction(function () use ($assignment, $values, $user, $blocksSlot): ProductionMachineAssignment {
                if ($values['production_method'] === ProductFamily::METHOD_MACHINE_MOULD) {
                    $lockedMachine = Machine::query()->forCurrentCompany()->whereKey($values['machine_id'])->lockForUpdate()->firstOrFail();
                    $currentInstallation = $lockedMachine->currentMouldInstallation()->lockForUpdate()->first();
                    if (! $currentInstallation
                        || (int) $currentInstallation->id !== (int) $values['production_mould_installation_id']
                        || (int) $currentInstallation->production_mould_id !== (int) $values['production_mould_id']) {
                        throw ValidationException::withMessages(['machine_id' => __('production.validation.assignment_mould_changed')]);
                    }
                } else {
                    ProductionMould::query()->forCurrentCompany()->whereKey($values['production_mould_id'])->lockForUpdate()->firstOrFail();
                }

                $duplicate = $blocksSlot
                    ? ProductionMachineAssignment::query()
                        ->forCurrentCompany()
                        ->blocking()
                        ->where($values['production_method'] === ProductFamily::METHOD_MACHINE_MOULD ? 'machine_id' : 'production_mould_id', $values['production_method'] === ProductFamily::METHOD_MACHINE_MOULD ? $values['machine_id'] : $values['production_mould_id'])
                        ->whereDate('production_date', $values['production_date'])
                        ->when($assignment, fn ($query) => $query->whereKeyNot($assignment->id))
                        ->with('product')
                        ->lockForUpdate()
                        ->first()
                    : null;
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        ($values['production_method'] === ProductFamily::METHOD_MACHINE_MOULD ? 'machine_id' : 'production_mould_id') => __('production.validation.duplicate', [
                            'product' => $duplicate->product?->name ?: __('production.unknown_product'),
                            'date' => $values['production_date'],
                        ]),
                    ]);
                }

                if ($assignment) {
                    $assignment = ProductionMachineAssignment::query()->forCurrentCompany()
                        ->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                    if ($assignment->immutableProductionOrder()) {
                        throw ValidationException::withMessages([
                            'status' => 'This historical assignment is linked to a production order and cannot be changed. Create a new assignment.',
                        ]);
                    }
                    $assignment->update($values);

                    return $assignment->refresh();
                }

                return ProductionMachineAssignment::query()->create([
                    ...$values,
                    'created_by' => $user->id,
                ]);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'machine_id' => __('production.validation.duplicate_generic'),
                ]);
            }

            throw $exception;
        }
    }
}
