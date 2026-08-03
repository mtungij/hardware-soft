<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_recipe_items', function (Blueprint $table): void {
            $table->decimal('source_quantity', 24, 12)->nullable()->change();
            $table->decimal('yield_quantity', 24, 12)->nullable()->change();
            $table->decimal('normalized_quantity', 24, 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('production_recipe_items', function (Blueprint $table): void {
            $table->decimal('source_quantity', 18, 8)->nullable()->change();
            $table->decimal('yield_quantity', 18, 8)->nullable()->change();
            $table->decimal('normalized_quantity', 18, 8)->nullable()->change();
        });
    }
};
