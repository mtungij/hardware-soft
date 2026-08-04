<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BLOCKING_STATUSES = ['planned', 'confirmed'];

    public function up(): void
    {
        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->string('active_slot_key', 191)->nullable()->after('status');
        });

        DB::table('production_machine_assignments')
            ->whereNull('deleted_at')
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->orderBy('id')
            ->each(function (object $assignment): void {
                DB::table('production_machine_assignments')->where('id', $assignment->id)->update([
                    'active_slot_key' => implode(':', [
                        (int) $assignment->company_id,
                        (int) $assignment->machine_id,
                        substr((string) $assignment->production_date, 0, 10),
                    ]),
                ]);
            });

        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->dropUnique('prod_asn_day_uq');
            $table->unique('active_slot_key', 'prod_asn_active_slot_uq');
        });
    }

    public function down(): void
    {
        $duplicateHistoryExists = DB::table('production_machine_assignments')
            ->select(['company_id', 'machine_id', 'production_date'])
            ->groupBy('company_id', 'machine_id', 'production_date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateHistoryExists) {
            throw new RuntimeException('Cannot restore the legacy assignment uniqueness constraint without losing retained history.');
        }

        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->dropUnique('prod_asn_active_slot_uq');
            $table->dropColumn('active_slot_key');
            $table->unique(['company_id', 'machine_id', 'production_date'], 'prod_asn_day_uq');
        });
    }
};
