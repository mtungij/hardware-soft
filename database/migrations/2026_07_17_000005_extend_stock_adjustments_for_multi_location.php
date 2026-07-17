<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'stock_adjustment_approval_required')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->boolean('stock_adjustment_approval_required')->default(true)->after('credit_limit_enforcement');
            });
        }

        if (Schema::hasTable('user_stock_locations') && ! Schema::hasColumn('user_stock_locations', 'can_adjust')) {
            Schema::table('user_stock_locations', function (Blueprint $table): void {
                $table->boolean('can_adjust')->default(false)->after('can_receive');
            });

            DB::table('user_stock_locations')->where('can_receive', true)->update(['can_adjust' => true]);
        }

        if (Schema::hasTable('stock_adjustments')) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE stock_adjustments MODIFY status ENUM('draft','pending','pending_approval','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'pending_approval'");
            }

            Schema::table('stock_adjustments', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_adjustments', 'reference_number')) {
                    $table->string('reference_number')->nullable()->after('stock_location_id');
                }
                if (! Schema::hasColumn('stock_adjustments', 'adjustment_date')) {
                    $table->date('adjustment_date')->nullable()->after('reference_number');
                }
                if (! Schema::hasColumn('stock_adjustments', 'approval_comments')) {
                    $table->text('approval_comments')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('stock_adjustments', 'posted_by')) {
                    $table->foreignId('posted_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_adjustments', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('posted_by');
                }
                if (! Schema::hasColumn('stock_adjustments', 'reversed_by')) {
                    $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_adjustments', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable()->after('reversed_by');
                }
                if (! Schema::hasColumn('stock_adjustments', 'reversal_of_id')) {
                    $table->foreignId('reversal_of_id')->nullable()->after('reversed_at')->constrained('stock_adjustments')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('stock_adjustment_lines')) {
            Schema::create('stock_adjustment_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->decimal('system_quantity', 15, 2)->default(0);
                $table->decimal('physical_quantity', 15, 2)->default(0);
                $table->decimal('difference_quantity', 15, 2)->default(0);
                $table->string('adjustment_type')->nullable();
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['stock_adjustment_id', 'product_id'], 'stock_adjustment_product_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
};
