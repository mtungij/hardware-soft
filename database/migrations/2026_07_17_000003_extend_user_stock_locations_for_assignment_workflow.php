<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_stock_locations')) {
            return;
        }

        Schema::table('user_stock_locations', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_stock_locations', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('user_stock_locations', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->after('is_default')->constrained('users')->nullOnDelete();
            }

        });

        if (! $this->indexExists('user_stock_locations', 'user_stock_locations_company_branch_index')) {
            Schema::table('user_stock_locations', function (Blueprint $table): void {
                $table->index(['company_id', 'branch_id'], 'user_stock_locations_company_branch_index');
            });
        }

        DB::table('user_stock_locations')
            ->whereNull('branch_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $branchId = DB::table('stock_locations')
                        ->where('id', $row->stock_location_id)
                        ->value('branch_id');

                    if ($branchId) {
                        DB::table('user_stock_locations')
                            ->where('id', $row->id)
                            ->update(['branch_id' => $branchId]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_stock_locations')) {
            return;
        }

        Schema::table('user_stock_locations', function (Blueprint $table): void {
            try {
                $table->dropIndex('user_stock_locations_company_branch_index');
            } catch (Throwable) {
                //
            }

            if (Schema::hasColumn('user_stock_locations', 'assigned_by')) {
                $table->dropConstrainedForeignId('assigned_by');
            }

            if (Schema::hasColumn('user_stock_locations', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
