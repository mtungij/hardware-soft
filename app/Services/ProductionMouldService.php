<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\ProductionMould;
use App\Models\ProductionMouldInstallation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionMouldService
{
    public function install(Machine $machine, ProductionMould $mould, User $user, ?string $notes = null): ProductionMouldInstallation
    {
        return DB::transaction(function () use ($machine, $mould, $user, $notes): ProductionMouldInstallation {
            [$machine, $mould] = $this->lockedPair($machine, $mould, $user);
            $this->assertInstallable($machine, $mould);

            if ($this->currentForMachine($machine)->exists()) {
                throw ValidationException::withMessages(['machine_id' => __('production.moulds.validation.machine_has_mould')]);
            }
            if ($this->currentForMould($mould)->exists()) {
                throw ValidationException::withMessages(['mould_id' => __('production.moulds.validation.mould_installed_elsewhere')]);
            }

            return $this->createInstallation($machine, $mould, $user, $notes);
        });
    }

    public function replace(Machine $machine, ProductionMould $mould, User $user, ?string $notes = null): ProductionMouldInstallation
    {
        return DB::transaction(function () use ($machine, $mould, $user, $notes): ProductionMouldInstallation {
            [$machine, $mould] = $this->lockedPair($machine, $mould, $user);
            $this->assertInstallable($machine, $mould);
            $current = $this->currentForMachine($machine)->lockForUpdate()->first();

            if (! $current) {
                throw ValidationException::withMessages(['machine_id' => __('production.moulds.validation.no_installed_mould')]);
            }
            if ((int) $current->production_mould_id === (int) $mould->id) {
                throw ValidationException::withMessages(['mould_id' => __('production.moulds.validation.already_installed')]);
            }
            if ($this->currentForMould($mould)->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['mould_id' => __('production.moulds.validation.mould_installed_elsewhere')]);
            }

            $this->closeInstallation($current, $user, ProductionMouldInstallation::REASON_REPLACED, $notes);

            return $this->createInstallation($machine, $mould, $user, $notes);
        });
    }

    public function remove(Machine $machine, User $user, ?string $notes = null): ProductionMouldInstallation
    {
        return DB::transaction(function () use ($machine, $user, $notes): ProductionMouldInstallation {
            $machine = $this->lockedMachine($machine, $user);
            $current = $this->currentForMachine($machine)->lockForUpdate()->first();

            if (! $current) {
                throw ValidationException::withMessages(['machine_id' => __('production.moulds.validation.no_installed_mould')]);
            }

            return $this->closeInstallation($current, $user, ProductionMouldInstallation::REASON_REMOVED, $notes);
        });
    }

    public function startMaintenance(ProductionMould $mould, User $user, ?string $notes = null): ProductionMould
    {
        return DB::transaction(function () use ($mould, $user, $notes): ProductionMould {
            $mould = $this->lockedMould($mould, $user);
            $current = $this->currentForMould($mould)->lockForUpdate()->first();
            if ($current) {
                $this->closeInstallation($current, $user, ProductionMouldInstallation::REASON_MAINTENANCE, $notes);
            }
            $mould->update(['under_maintenance' => true, 'updated_by' => $user->id]);

            return $mould->refresh();
        });
    }

    public function completeMaintenance(ProductionMould $mould, User $user): ProductionMould
    {
        return DB::transaction(function () use ($mould, $user): ProductionMould {
            $mould = $this->lockedMould($mould, $user);
            $mould->update(['under_maintenance' => false, 'updated_by' => $user->id]);

            return $mould->refresh();
        });
    }

    private function lockedPair(Machine $machine, ProductionMould $mould, User $user): array
    {
        return [$this->lockedMachine($machine, $user), $this->lockedMould($mould, $user)];
    }

    private function lockedMachine(Machine $machine, User $user): Machine
    {
        abort_unless((int) $machine->company_id === (int) $user->company_id, 404);

        return Machine::query()->forCurrentCompany()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedMould(ProductionMould $mould, User $user): ProductionMould
    {
        abort_unless((int) $mould->company_id === (int) $user->company_id, 404);

        return ProductionMould::query()->forCurrentCompany()->whereKey($mould->id)->lockForUpdate()->firstOrFail();
    }

    private function assertInstallable(Machine $machine, ProductionMould $mould): void
    {
        if ($machine->status !== Machine::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['machine_id' => __('production.moulds.validation.machine_not_active')]);
        }
        if (! $mould->active || $mould->under_maintenance) {
            throw ValidationException::withMessages(['mould_id' => __('production.moulds.validation.mould_not_available')]);
        }
        if (! $mould->compatibleMachines()->whereKey($machine->id)->exists()) {
            throw ValidationException::withMessages(['mould_id' => __('production.moulds.validation.not_compatible')]);
        }
    }

    private function currentForMachine(Machine $machine)
    {
        return ProductionMouldInstallation::query()->forCurrentCompany()->current()
            ->where('current_machine_id', $machine->id);
    }

    private function currentForMould(ProductionMould $mould)
    {
        return ProductionMouldInstallation::query()->forCurrentCompany()->current()
            ->where('current_mould_id', $mould->id);
    }

    private function createInstallation(Machine $machine, ProductionMould $mould, User $user, ?string $notes): ProductionMouldInstallation
    {
        return ProductionMouldInstallation::query()->create([
            'company_id' => $machine->company_id,
            'machine_id' => $machine->id,
            'production_mould_id' => $mould->id,
            'current_machine_id' => $machine->id,
            'current_mould_id' => $mould->id,
            'installed_at' => now(),
            'installed_by' => $user->id,
            'notes' => filled($notes) ? trim((string) $notes) : null,
        ]);
    }

    private function closeInstallation(ProductionMouldInstallation $installation, User $user, string $reason, ?string $notes): ProductionMouldInstallation
    {
        $installation->update([
            'current_machine_id' => null,
            'current_mould_id' => null,
            'removed_at' => now(),
            'removed_by' => $user->id,
            'removal_reason' => $reason,
            'notes' => filled($notes) ? trim((string) $notes) : $installation->notes,
        ]);

        return $installation->refresh();
    }
}
