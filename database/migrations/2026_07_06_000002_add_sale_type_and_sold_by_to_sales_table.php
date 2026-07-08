<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->enum('sale_type', ['retail', 'wholesale'])->default('retail')->after('sale_date');
            $table->foreignId('sold_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        DB::table('sales')->whereNull('sold_by')->update([
            'sold_by' => DB::raw('created_by'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sold_by');
            $table->dropColumn('sale_type');
        });
    }
};
