<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_recipe_items', function (Blueprint $table): void {
            $table->string('authoring_basis', 30)->nullable()->after('entry_mode');
            $table->decimal('authoring_quantity', 24, 12)->nullable()->after('authoring_basis');
            $table->decimal('authoring_unit_cost', 24, 8)->nullable()->after('authoring_quantity');
            $table->decimal('authoring_output_quantity', 24, 12)->nullable()->after('authoring_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_recipe_items', function (Blueprint $table): void {
            $table->dropColumn([
                'authoring_basis',
                'authoring_quantity',
                'authoring_unit_cost',
                'authoring_output_quantity',
            ]);
        });
    }
};
