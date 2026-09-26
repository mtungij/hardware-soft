<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_cost_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('purchase_item_cost_breakdowns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_cost_type_id')->constrained()->restrictOnDelete();
            $table->string('cost_type_name_snapshot', 100);
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'purchase_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_item_cost_breakdowns');
        Schema::dropIfExists('purchase_cost_types');
    }
};
