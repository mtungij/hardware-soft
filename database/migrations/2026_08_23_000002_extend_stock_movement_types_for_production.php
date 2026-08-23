<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_TYPES = [
        'purchase_in',
        'purchase_receipt',
        'purchase_receipt_reversal',
        'transfer_in',
        'transfer_out',
        'sale_out',
        'adjustment_in',
        'adjustment_out',
        'damage_out',
        'return_in',
        'direct_stock_in',
    ];

    private const PRODUCTION_TYPES = [
        'production_output',
        'production_consumption',
        'curing_release_in',
        'curing_release_out',
        'curing_damage',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->alterEnum([...self::PREVIOUS_TYPES, ...self::PRODUCTION_TYPES]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Shrinking a MySQL enum while these values exist can coerce or truncate
        // historical movements. Refuse rollback instead of rewriting ledger data.
        if (DB::table('stock_movements')->whereIn('movement_type', self::PRODUCTION_TYPES)->exists()) {
            throw new RuntimeException(
                'Cannot restore the previous stock_movements.movement_type enum while production or curing movements exist. Rollback would be destructive.'
            );
        }

        $this->alterEnum(self::PREVIOUS_TYPES);
    }

    /** @param array<int, string> $types */
    private function alterEnum(array $types): void
    {
        $enum = collect($types)
            ->map(fn (string $type): string => "'".str_replace("'", "''", $type)."'")
            ->implode(',');

        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM({$enum}) NOT NULL");
    }
};
