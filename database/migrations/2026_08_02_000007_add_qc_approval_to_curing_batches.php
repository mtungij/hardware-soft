<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->timestamp('qc_approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('qc_approved_at')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('production_curing_batches')->orderBy('id')->each(function (object $batch): void {
            $inspection = DB::table('production_quality_inspections')
                ->where('production_curing_batch_id', $batch->id)
                ->where('approval_status', 'approved')
                ->whereIn('result', ['passed', 'conditional'])
                ->latest('approved_at')
                ->first();
            if ($inspection && ! in_array($batch->status, ['released', 'closed', 'quarantined'], true)) {
                DB::table('production_curing_batches')->where('id', $batch->id)->update([
                    'status' => 'ready_for_release',
                    'qc_approved_at' => $inspection->approved_at,
                    'approved_by' => $inspection->approved_by,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('qc_approved_at');
        });
    }
};
