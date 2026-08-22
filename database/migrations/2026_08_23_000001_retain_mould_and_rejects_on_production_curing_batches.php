<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->foreignId('production_mould_id')->nullable()->after('machine_id')
                ->constrained('production_moulds')->nullOnDelete();
            $table->decimal('production_rejected_quantity', 24, 12)->default(0)->after('accepted_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('production_mould_id');
            $table->dropColumn('production_rejected_quantity');
        });
    }
};
