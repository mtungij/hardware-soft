<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_quality_inspections', function (Blueprint $table): void {
            $table->foreignId('production_quality_plan_id')->nullable()->change();
            $table->foreignId('recipe_snapshot_id')->nullable()->after('production_curing_batch_id')
                ->constrained('production_order_recipe_snapshots')->restrictOnDelete();
        });

        DB::table('production_curing_batches as batches')
            ->join('production_orders as orders', 'orders.id', '=', 'batches.production_order_id')
            ->join('products', 'products.id', '=', 'batches.product_id')
            ->leftJoin('production_order_recipe_snapshots as snapshots', 'snapshots.production_order_id', '=', 'orders.id')
            ->where('products.requires_quality_control', true)
            ->whereNotNull('orders.completed_by')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('production_quality_inspections as inspections')
                    ->whereColumn('inspections.production_curing_batch_id', 'batches.id')
                    ->where('inspections.inspection_stage', 'pre_release')
                    ->whereNull('inspections.supersedes_inspection_id');
            })
            ->select([
                'batches.id as batch_id', 'batches.company_id', 'batches.branch_id',
                'batches.production_order_id', 'batches.product_id', 'batches.machine_id',
                'batches.accepted_quantity', 'orders.order_number', 'orders.completed_by',
                'orders.completed_at', 'snapshots.id as snapshot_id',
            ])
            ->orderBy('batches.id')
            ->each(function (object $row): void {
                DB::table('production_quality_inspections')->insert([
                    'company_id' => $row->company_id,
                    'branch_id' => $row->branch_id,
                    'production_quality_plan_id' => null,
                    'production_order_id' => $row->production_order_id,
                    'production_curing_batch_id' => $row->batch_id,
                    'recipe_snapshot_id' => $row->snapshot_id,
                    'product_id' => $row->product_id,
                    'machine_id' => $row->machine_id,
                    'inspection_number' => 'QIN-AUTO-'.$row->order_number,
                    'inspection_stage' => 'pre_release',
                    'applicable_quantity' => $row->accepted_quantity,
                    'result' => 'pending',
                    'approval_status' => 'pending',
                    'inspected_at' => $row->completed_at ?: now(),
                    'inspected_by' => $row->completed_by,
                    'notes' => 'Automatically queued when production completed.',
                    'created_at' => $row->completed_at ?: now(),
                    'updated_at' => $row->completed_at ?: now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('production_quality_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipe_snapshot_id');
        });
    }
};
