<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('units') || ! Schema::hasTable('companies') || ! Schema::hasTable('measurement_types')) {
            return;
        }

        $weightId = DB::table('measurement_types')->where('code', 'weight')->value('id');
        if (! $weightId) {
            return;
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (DB::table('units')->where('company_id', $companyId)->where('short_name', 'tonne')->exists()) {
                continue;
            }

            DB::table('units')->insert([
                'company_id' => $companyId,
                'name' => 'Tonne',
                'short_name' => 'tonne',
                'code' => 'tonne',
                'measurement_type_id' => $weightId,
                'description' => 'Tonne inventory unit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Units may be attached to products or historical documents; keep them on rollback.
    }
};
