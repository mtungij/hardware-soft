<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_curing_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('source_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('default_release_stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->string('batch_number', 60);
            $table->date('production_date');
            $table->timestamp('curing_started_at');
            $table->timestamp('minimum_sellable_at');
            $table->timestamp('full_curing_at');
            $table->decimal('accepted_quantity', 24, 12);
            $table->decimal('released_quantity', 24, 12)->default(0);
            $table->decimal('damaged_quantity', 24, 12)->default(0);
            $table->decimal('remaining_quantity', 24, 12);
            $table->string('status', 30)->default('curing');
            $table->text('notes')->nullable();
            $table->text('quarantine_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('production_order_id', 'cur_batch_order_uq');
            $table->unique(['company_id', 'batch_number'], 'cur_batch_number_uq');
            $table->index(['company_id', 'status'], 'cur_batch_status_ix');
            $table->index(['company_id', 'minimum_sellable_at'], 'cur_batch_sellable_ix');
        });

        Schema::create('production_curing_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_curing_batch_id')->constrained('production_curing_batches')->restrictOnDelete();
            $table->string('release_number', 60);
            $table->decimal('released_quantity', 24, 12);
            $table->foreignId('source_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('destination_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->timestamp('released_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('posting_reference', 100);
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['company_id', 'release_number'], 'cur_release_number_uq');
            $table->unique(['company_id', 'posting_reference'], 'cur_release_posting_uq');
            $table->unique(['company_id', 'idempotency_key'], 'cur_release_idem_uq');
            $table->index('production_curing_batch_id', 'cur_release_batch_ix');
        });

        Schema::create('production_curing_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_curing_batch_id')->constrained('production_curing_batches')->restrictOnDelete();
            $table->string('action_type', 30);
            $table->decimal('quantity', 24, 12)->nullable();
            $table->text('reason');
            $table->string('posting_reference', 100)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'cur_action_idem_uq');
            $table->index(['production_curing_batch_id', 'action_type'], 'cur_action_batch_type_ix');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('production_curing_batch_id')->nullable()->after('reference_id')
                ->constrained('production_curing_batches')->restrictOnDelete();
            $table->foreignId('production_curing_release_id')->nullable()->after('production_curing_batch_id')
                ->constrained('production_curing_releases')->restrictOnDelete();
            $table->string('posting_reference', 100)->nullable()->after('production_curing_release_id');
            $table->index(['company_id', 'posting_reference'], 'stk_move_posting_ix');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stk_move_posting_ix');
            $table->dropConstrainedForeignId('production_curing_release_id');
            $table->dropConstrainedForeignId('production_curing_batch_id');
            $table->dropColumn('posting_reference');
        });
        Schema::dropIfExists('production_curing_actions');
        Schema::dropIfExists('production_curing_releases');
        Schema::dropIfExists('production_curing_batches');
    }
};
