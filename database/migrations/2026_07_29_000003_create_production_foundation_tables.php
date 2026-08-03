<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->decimal('daily_capacity', 18, 4)->nullable();
            $table->string('capacity_unit', 40)->default('pcs_per_day');
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'mach_co_name_uq');
            $table->unique(['company_id', 'code'], 'mach_co_code_uq');
            $table->index(['company_id', 'status'], 'mach_co_status_ix');
            $table->index(['company_id', 'branch_id'], 'mach_co_branch_ix');
        });

        Schema::create('production_machine_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->date('production_date');
            $table->decimal('target_quantity', 18, 4)->nullable();
            $table->time('planned_start_time')->nullable();
            $table->time('planned_end_time')->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'machine_id', 'production_date'], 'prod_asn_day_uq');
            $table->index(['company_id', 'production_date'], 'prod_asn_date_ix');
            $table->index(['company_id', 'branch_id'], 'prod_asn_branch_ix');
            $table->index(['company_id', 'status'], 'prod_asn_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_machine_assignments');
        Schema::dropIfExists('machines');
    }
};
