<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->rebuild([
            'purchase_in',
            'transfer_in',
            'transfer_out',
            'sale_out',
            'adjustment_in',
            'adjustment_out',
            'damage_out',
            'return_in',
            'direct_stock_in',
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::table('stock_movements')
            ->where('movement_type', 'direct_stock_in')
            ->update(['movement_type' => 'adjustment_in']);

        $this->rebuild([
            'purchase_in',
            'transfer_in',
            'transfer_out',
            'sale_out',
            'adjustment_in',
            'adjustment_out',
            'damage_out',
            'return_in',
        ]);
    }

    private function rebuild(array $movementTypes): void
    {
        $allowed = collect($movementTypes)
            ->map(fn (string $type) => "'".$type."'")
            ->join(', ');

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement(<<<SQL
CREATE TABLE stock_movements_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    branch_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    stock_location_id INTEGER NOT NULL,
    movement_type VARCHAR NOT NULL CHECK (movement_type IN ($allowed)),
    quantity NUMERIC NOT NULL,
    unit_cost NUMERIC,
    unit_price NUMERIC,
    reference_type VARCHAR,
    reference_id INTEGER,
    notes TEXT,
    created_by INTEGER NOT NULL,
    movement_date DATE NOT NULL,
    created_at DATETIME,
    updated_at DATETIME,
    FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY(stock_location_id) REFERENCES stock_locations(id) ON DELETE RESTRICT,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);

        DB::statement(<<<SQL
INSERT INTO stock_movements_new (
    id, branch_id, product_id, stock_location_id, movement_type, quantity, unit_cost, unit_price,
    reference_type, reference_id, notes, created_by, movement_date, created_at, updated_at
)
SELECT
    id, branch_id, product_id, stock_location_id, movement_type, quantity, unit_cost, unit_price,
    reference_type, reference_id, notes, created_by, movement_date, created_at, updated_at
FROM stock_movements
SQL);

        DB::statement('DROP TABLE stock_movements');
        DB::statement('ALTER TABLE stock_movements_new RENAME TO stock_movements');
        DB::statement('CREATE INDEX stock_movements_reference_type_reference_id_index ON stock_movements (reference_type, reference_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
