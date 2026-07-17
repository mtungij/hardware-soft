<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendGoodsReceivingNotes();
        $this->extendGoodsReceivingNoteItems();
        $this->createProductLocationSettings();
        $this->extendMovementTypes();
        $this->backfillExistingReceipts();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_location_settings');

        if (Schema::hasTable('goods_receiving_note_items')) {
            Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
                foreach (['branch_id', 'stock_location_id', 'ordered_quantity', 'previously_received_quantity', 'unit_cost', 'total_cost', 'batch_number', 'expiry_date', 'notes'] as $column) {
                    if (Schema::hasColumn('goods_receiving_note_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('goods_receiving_notes')) {
            Schema::table('goods_receiving_notes', function (Blueprint $table): void {
                foreach (['supplier_delivery_note_number', 'supplier_invoice_number', 'default_stock_location_id', 'status', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'] as $column) {
                    if (Schema::hasColumn('goods_receiving_notes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function extendGoodsReceivingNotes(): void
    {
        if (! Schema::hasTable('goods_receiving_notes')) {
            return;
        }

        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receiving_notes', 'supplier_delivery_note_number')) {
                $table->string('supplier_delivery_note_number')->nullable()->after('received_date');
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'supplier_invoice_number')) {
                $table->string('supplier_invoice_number')->nullable()->after('supplier_delivery_note_number');
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'default_stock_location_id')) {
                $table->foreignId('default_stock_location_id')->nullable()->after('stock_location_id')->constrained('stock_locations')->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'status')) {
                $table->string('status', 30)->default('posted')->after('supplier_invoice_number');
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'posted_by')) {
                $table->foreignId('posted_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('posted_by');
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }

            if (! Schema::hasColumn('goods_receiving_notes', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            }
        });

        DB::table('goods_receiving_notes')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'posted', 'posted_at' => DB::raw('created_at')]);
    }

    private function extendGoodsReceivingNoteItems(): void
    {
        if (! Schema::hasTable('goods_receiving_note_items')) {
            return;
        }

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receiving_note_items', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'stock_location_id')) {
                $table->foreignId('stock_location_id')->nullable()->after('product_id')->constrained('stock_locations')->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'ordered_quantity')) {
                $table->decimal('ordered_quantity', 15, 2)->default(0)->after('stock_location_id');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'previously_received_quantity')) {
                $table->decimal('previously_received_quantity', 15, 2)->default(0)->after('ordered_quantity');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->nullable()->after('received_quantity');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'total_cost')) {
                $table->decimal('total_cost', 15, 2)->default(0)->after('unit_cost');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'batch_number')) {
                $table->string('batch_number')->nullable()->after('total_cost');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            }

            if (! Schema::hasColumn('goods_receiving_note_items', 'notes')) {
                $table->text('notes')->nullable()->after('expiry_date');
            }
        });
    }

    private function createProductLocationSettings(): void
    {
        if (Schema::hasTable('product_location_settings')) {
            return;
        }

        Schema::create('product_location_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('preferred_receiving_location_id')->nullable();
            $table->foreign('preferred_receiving_location_id', 'pls_pref_receiving_location_fk')
                ->references('id')
                ->on('stock_locations')
                ->nullOnDelete();
            $table->decimal('reorder_level', 15, 2)->nullable();
            $table->decimal('reorder_quantity', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'product_id', 'stock_location_id'], 'product_location_settings_unique');
        });
    }

    private function extendMovementTypes(): void
    {
        if (! Schema::hasTable('stock_movements') || Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM('purchase_in','purchase_receipt','purchase_receipt_reversal','transfer_in','transfer_out','sale_out','adjustment_in','adjustment_out','damage_out','return_in','direct_stock_in') NOT NULL");
    }

    private function backfillExistingReceipts(): void
    {
        if (! Schema::hasTable('goods_receiving_notes') || ! Schema::hasTable('goods_receiving_note_items')) {
            return;
        }

        DB::table('goods_receiving_notes')->orderBy('id')->chunkById(200, function ($receipts): void {
            foreach ($receipts as $receipt) {
                DB::table('goods_receiving_notes')->where('id', $receipt->id)->update([
                    'default_stock_location_id' => $receipt->stock_location_id,
                    'status' => $receipt->status ?: 'posted',
                    'posted_by' => $receipt->posted_by ?: $receipt->received_by,
                    'posted_at' => $receipt->posted_at ?: $receipt->created_at,
                ]);

                DB::table('goods_receiving_note_items')
                    ->where('goods_receiving_note_id', $receipt->id)
                    ->update([
                        'branch_id' => $receipt->branch_id,
                        'stock_location_id' => $receipt->stock_location_id,
                        'unit_cost' => DB::raw('cost_price'),
                        'total_cost' => DB::raw('received_quantity * cost_price'),
                    ]);
            }
        });
    }
};
