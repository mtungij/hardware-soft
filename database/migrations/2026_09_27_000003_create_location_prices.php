<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_conversion_id')->nullable()->constrained()->restrictOnDelete();
            // A non-null key makes base-unit rows unique on MySQL and SQLite alike.
            $table->unsignedBigInteger('unit_key')->default(0);
            $table->decimal('retail_price', 18, 2)->nullable();
            $table->decimal('wholesale_price', 18, 2)->nullable();
            $table->decimal('internal_sale_price', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'stock_location_id', 'unit_key'], 'location_prices_unique_unit');
            $table->index(['company_id', 'stock_location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_prices');
    }
};
