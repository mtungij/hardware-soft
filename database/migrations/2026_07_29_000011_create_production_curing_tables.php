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
            $table->foreignId('company_id')->constrained(indexName: 'pcb_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained(indexName: 'pcb_branch_fk')->nullOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders', indexName: 'pcb_order_fk')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', indexName: 'pcb_product_fk')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines', indexName: 'pcb_machine_fk')->nullOnDelete();
            $table->foreignId('source_stock_location_id')->constrained('stock_locations', indexName: 'pcb_source_location_fk')->restrictOnDelete();
            $table->foreignId('default_release_stock_location_id')->nullable()->constrained('stock_locations', indexName: 'pcb_release_location_fk')->restrictOnDelete();
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
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pcb_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'pcb_updated_by_fk')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users', indexName: 'pcb_closed_by_fk')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('production_order_id', 'cur_batch_order_uq');
            $table->unique(['company_id', 'batch_number'], 'cur_batch_number_uq');
            $table->index(['company_id', 'status'], 'cur_batch_status_ix');
            $table->index(['company_id', 'minimum_sellable_at'], 'cur_batch_sellable_ix');
        });

        Schema::create('production_curing_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pcr_company_fk')->cascadeOnDelete();
            $table->foreignId('production_curing_batch_id')->constrained('production_curing_batches', indexName: 'pcr_batch_fk')->restrictOnDelete();
            $table->string('release_number', 60);
            $table->decimal('released_quantity', 24, 12);
            $table->foreignId('source_stock_location_id')->constrained('stock_locations', indexName: 'pcr_source_location_fk')->restrictOnDelete();
            $table->foreignId('destination_stock_location_id')->constrained('stock_locations', indexName: 'pcr_destination_location_fk')->restrictOnDelete();
            $table->timestamp('released_at');
            $table->foreignId('released_by')->nullable()->constrained('users', indexName: 'pcr_released_by_fk')->nullOnDelete();
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
            $table->foreignId('company_id')->constrained(indexName: 'pca_company_fk')->cascadeOnDelete();
            $table->foreignId('production_curing_batch_id')->constrained('production_curing_batches', indexName: 'pca_batch_fk')->restrictOnDelete();
            $table->string('action_type', 30);
            $table->decimal('quantity', 24, 12)->nullable();
            $table->text('reason');
            $table->string('posting_reference', 100)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pca_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'cur_action_idem_uq');
            $table->index(['production_curing_batch_id', 'action_type'], 'cur_action_batch_type_ix');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('production_curing_batch_id')->nullable()->after('reference_id')
                ->constrained('production_curing_batches', indexName: 'sm_curing_batch_fk')->restrictOnDelete();
            $table->foreignId('production_curing_release_id')->nullable()->after('production_curing_batch_id')
                ->constrained('production_curing_releases', indexName: 'sm_curing_release_fk')->restrictOnDelete();
            $table->string('posting_reference', 100)->nullable()->after('production_curing_release_id');
            $table->index(['company_id', 'posting_reference'], 'stk_move_posting_ix');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stk_move_posting_ix');
            $table->dropForeign('sm_curing_release_fk');
            $table->dropForeign('sm_curing_batch_fk');
            $table->dropColumn(['production_curing_release_id', 'production_curing_batch_id']);
            $table->dropColumn('posting_reference');
        });
        Schema::dropIfExists('production_curing_actions');
        Schema::dropIfExists('production_curing_releases');
        Schema::dropIfExists('production_curing_batches');
    }
};
