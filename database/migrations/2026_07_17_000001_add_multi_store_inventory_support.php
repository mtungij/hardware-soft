<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendSettings();
        $this->extendStockLocations();
        $this->extendStockMovements();
        $this->extendSales();
        $this->extendStockTransfers();
        $this->createUserStockLocations();
        $this->backfillLocations();
        $this->backfillUserLocations();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stock_locations');

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'stock_location_id')) {
            Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_location_id'));
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                foreach (['quantity_in', 'quantity_out', 'source_location_id', 'destination_location_id'] as $column) {
                    if (Schema::hasColumn('stock_movements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table): void {
                foreach (['inventory_mode', 'allow_multiple_dispensing_locations'] as $column) {
                    if (Schema::hasColumn('settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_locations')) {
            Schema::table('stock_locations', function (Blueprint $table): void {
                foreach ([
                    'description',
                    'is_default',
                    'is_active',
                    'can_receive_stock',
                    'can_issue_stock',
                    'can_sell',
                    'can_transfer',
                    'can_transfer_to_dispensing',
                    'is_dispensing_location',
                    'is_warehouse',
                    'created_by',
                    'deleted_at',
                ] as $column) {
                    if (Schema::hasColumn('stock_locations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function extendSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('settings', 'inventory_mode')) {
                $table->string('inventory_mode', 40)->default('multi_location')->after('allow_sales_from_store');
            }

            if (! Schema::hasColumn('settings', 'allow_multiple_dispensing_locations')) {
                $table->boolean('allow_multiple_dispensing_locations')->default(false)->after('inventory_mode');
            }
        });

        DB::table('settings')
            ->whereNull('inventory_mode')
            ->orWhere('inventory_mode', '')
            ->update(['inventory_mode' => DB::raw("CASE WHEN enable_warehouse = 1 THEN 'multi_location' ELSE 'single_location' END")]);
    }

    private function extendStockLocations(): void
    {
        if (! Schema::hasTable('stock_locations')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_locations MODIFY type ENUM('warehouse','store','dispensing','showroom','branch_store','returns','damaged','transit','other') NOT NULL DEFAULT 'store'");
        }

        Schema::table('stock_locations', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_locations', 'description')) {
                $table->text('description')->nullable()->after('type');
            }

            if (! Schema::hasColumn('stock_locations', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('status');
            }

            if (! Schema::hasColumn('stock_locations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_default');
            }

            if (! Schema::hasColumn('stock_locations', 'can_receive_stock')) {
                $table->boolean('can_receive_stock')->default(true)->after('is_active');
            }

            if (! Schema::hasColumn('stock_locations', 'can_issue_stock')) {
                $table->boolean('can_issue_stock')->default(true)->after('can_receive_stock');
            }

            if (! Schema::hasColumn('stock_locations', 'can_sell')) {
                $table->boolean('can_sell')->default(false)->after('can_issue_stock');
            }

            if (! Schema::hasColumn('stock_locations', 'can_transfer')) {
                $table->boolean('can_transfer')->default(true)->after('can_sell');
            }

            if (! Schema::hasColumn('stock_locations', 'can_transfer_to_dispensing')) {
                $table->boolean('can_transfer_to_dispensing')->default(true)->after('can_transfer');
            }

            if (! Schema::hasColumn('stock_locations', 'is_dispensing_location')) {
                $table->boolean('is_dispensing_location')->default(false)->after('can_transfer_to_dispensing');
            }

            if (! Schema::hasColumn('stock_locations', 'is_warehouse')) {
                $table->boolean('is_warehouse')->default(false)->after('is_dispensing_location');
            }

            if (! Schema::hasColumn('stock_locations', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_warehouse')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('stock_locations', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('stock_locations')->update([
            'is_active' => DB::raw("CASE WHEN status = 'active' THEN 1 ELSE 0 END"),
            'is_dispensing_location' => DB::raw("CASE WHEN type = 'dispensing' THEN 1 ELSE 0 END"),
            'is_warehouse' => DB::raw("CASE WHEN type = 'warehouse' THEN 1 ELSE 0 END"),
            'can_sell' => DB::raw("CASE WHEN type IN ('dispensing', 'store', 'showroom', 'branch_store') THEN 1 ELSE 0 END"),
        ]);
    }

    private function extendStockMovements(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'quantity_in')) {
                $table->decimal('quantity_in', 15, 2)->default(0)->after('quantity');
            }

            if (! Schema::hasColumn('stock_movements', 'quantity_out')) {
                $table->decimal('quantity_out', 15, 2)->default(0)->after('quantity_in');
            }

            if (! Schema::hasColumn('stock_movements', 'source_location_id')) {
                $table->foreignId('source_location_id')->nullable()->after('stock_location_id')->constrained('stock_locations')->nullOnDelete();
            }

            if (! Schema::hasColumn('stock_movements', 'destination_location_id')) {
                $table->foreignId('destination_location_id')->nullable()->after('source_location_id')->constrained('stock_locations')->nullOnDelete();
            }
        });

        DB::table('stock_movements')->whereIn('movement_type', ['purchase_in', 'transfer_in', 'adjustment_in', 'return_in', 'direct_stock_in'])->update([
            'quantity_in' => DB::raw('quantity'),
            'quantity_out' => 0,
        ]);

        DB::table('stock_movements')->whereIn('movement_type', ['sale_out', 'transfer_out', 'adjustment_out', 'damage_out'])->update([
            'quantity_in' => 0,
            'quantity_out' => DB::raw('quantity'),
        ]);
    }

    private function extendSales(): void
    {
        if (! Schema::hasTable('sales') || Schema::hasColumn('sales', 'stock_location_id')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('stock_location_id')->nullable()->after('branch_id')->constrained('stock_locations')->nullOnDelete();
        });

        DB::table('sales')
            ->whereNull('stock_location_id')
            ->orderBy('id')
            ->chunkById(500, function ($sales): void {
                foreach ($sales as $sale) {
                    $locationId = DB::table('sale_items')
                        ->where('sale_id', $sale->id)
                        ->whereNotNull('stock_location_id')
                        ->value('stock_location_id');

                    if ($locationId) {
                        DB::table('sales')->where('id', $sale->id)->update(['stock_location_id' => $locationId]);
                    }
                }
            });
    }

    private function extendStockTransfers(): void
    {
        if (! Schema::hasTable('stock_transfers')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE stock_transfers MODIFY status ENUM('draft','approved','dispatched','received','completed','cancelled') NOT NULL DEFAULT 'draft'");
        }
    }

    private function createUserStockLocations(): void
    {
        if (Schema::hasTable('user_stock_locations')) {
            return;
        }

        Schema::create('user_stock_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_sell')->default(false);
            $table->boolean('can_transfer')->default(false);
            $table->boolean('can_receive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'stock_location_id']);
        });
    }

    private function backfillLocations(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasTable('stock_locations')) {
            return;
        }

        foreach (DB::table('branches')->select('id', 'company_id')->get() as $branch) {
            $companyId = $branch->company_id ?: DB::table('settings')->value('company_id');

            $this->location(
                $companyId,
                (int) $branch->id,
                'Main Store',
                'MAIN-STORE',
                'store',
                ['is_default' => true, 'can_sell' => true, 'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true, 'can_transfer_to_dispensing' => true]
            );

            $this->location(
                $companyId,
                (int) $branch->id,
                'Dispensing Area',
                'DISPENSING',
                'dispensing',
                ['is_dispensing_location' => true, 'can_sell' => true, 'can_receive_stock' => true, 'can_issue_stock' => true]
            );
        }
    }

    private function location(?int $companyId, int $branchId, string $name, string $code, string $type, array $flags): void
    {
        $existing = DB::table('stock_locations')
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->first();

        $payload = array_merge([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'status' => 'active',
            'is_active' => true,
            'updated_at' => now(),
        ], $flags);

        if ($existing) {
            DB::table('stock_locations')->where('id', $existing->id)->update($payload);
            return;
        }

        $payload['created_at'] = now();
        DB::table('stock_locations')->insert($payload);
    }

    private function backfillUserLocations(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('user_stock_locations')) {
            return;
        }

        foreach (DB::table('users')->select('id', 'company_id', 'branch_id', 'sales_location_access')->get() as $user) {
            $branchId = $user->branch_id ?: DB::table('branches')->where('company_id', $user->company_id)->value('id') ?: DB::table('branches')->value('id');
            if (! $branchId) {
                continue;
            }

            $types = match ($user->sales_location_access ?: 'dispensing') {
                'store' => ['store'],
                'both' => ['store', 'dispensing'],
                default => ['dispensing'],
            };

            foreach ($types as $index => $type) {
                $location = DB::table('stock_locations')
                    ->where('branch_id', $branchId)
                    ->where('type', $type)
                    ->orderByDesc('is_default')
                    ->first();

                if (! $location) {
                    continue;
                }

                DB::table('user_stock_locations')->updateOrInsert(
                    ['user_id' => $user->id, 'stock_location_id' => $location->id],
                    [
                        'company_id' => $user->company_id ?: $location->company_id,
                        'can_view' => true,
                        'can_sell' => true,
                        'can_transfer' => in_array($type, ['store', 'warehouse'], true),
                        'can_receive' => in_array($type, ['store', 'dispensing'], true),
                        'is_default' => $index === 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
};
