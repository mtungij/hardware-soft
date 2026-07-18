<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'allow_credit_sale_without_customer')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->boolean('allow_credit_sale_without_customer')->default(true)->after('credit_limit_enforcement');
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                if (! Schema::hasColumn('customers', 'is_system_customer')) {
                    $table->boolean('is_system_customer')->default(false)->after('status');
                }

                if (! Schema::hasColumn('customers', 'is_unassigned_credit_customer')) {
                    $table->boolean('is_unassigned_credit_customer')->default(false)->after('is_system_customer');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales', 'credit_customer_unassigned')) {
                    $table->boolean('credit_customer_unassigned')->default(false)->after('customer_id');
                }

                if (! Schema::hasColumn('sales', 'credit_assignment_status')) {
                    $table->string('credit_assignment_status', 30)->default('assigned')->after('credit_customer_unassigned');
                }

                if (! Schema::hasColumn('sales', 'temporary_customer_name')) {
                    $table->string('temporary_customer_name')->nullable()->after('credit_assignment_status');
                }

                if (! Schema::hasColumn('sales', 'temporary_customer_phone')) {
                    $table->string('temporary_customer_phone', 30)->nullable()->after('temporary_customer_name');
                }

                if (! Schema::hasColumn('sales', 'project_name')) {
                    $table->string('project_name')->nullable()->after('temporary_customer_phone');
                }

                if (! Schema::hasColumn('sales', 'vehicle_number')) {
                    $table->string('vehicle_number')->nullable()->after('project_name');
                }

                if (! Schema::hasColumn('sales', 'expected_payment_date')) {
                    $table->date('expected_payment_date')->nullable()->after('vehicle_number');
                }

                if (! Schema::hasColumn('sales', 'credit_notes')) {
                    $table->text('credit_notes')->nullable()->after('expected_payment_date');
                }

                if (! Schema::hasColumn('sales', 'credit_assigned_by')) {
                    $table->foreignId('credit_assigned_by')->nullable()->after('credit_notes')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('sales', 'credit_assigned_at')) {
                    $table->timestamp('credit_assigned_at')->nullable()->after('credit_assigned_by');
                }

                if (! Schema::hasColumn('sales', 'credit_assignment_notes')) {
                    $table->text('credit_assignment_notes')->nullable()->after('credit_assigned_at');
                }
            });
        }

        $this->ensureSystemCustomers();
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                foreach ([
                    'credit_assignment_notes',
                    'credit_assigned_at',
                    'credit_assigned_by',
                    'credit_notes',
                    'expected_payment_date',
                    'vehicle_number',
                    'project_name',
                    'temporary_customer_phone',
                    'temporary_customer_name',
                    'credit_assignment_status',
                    'credit_customer_unassigned',
                ] as $column) {
                    if (Schema::hasColumn('sales', $column)) {
                        $column === 'credit_assigned_by'
                            ? $table->dropConstrainedForeignId($column)
                            : $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                foreach (['is_unassigned_credit_customer', 'is_system_customer'] as $column) {
                    if (Schema::hasColumn('customers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'allow_credit_sale_without_customer')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropColumn('allow_credit_sale_without_customer');
            });
        }
    }

    private function ensureSystemCustomers(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('customers')) {
            return;
        }

        DB::table('companies')->orderBy('id')->get(['id'])->each(function ($company): void {
            $exists = DB::table('customers')
                ->where('company_id', $company->id)
                ->where('is_unassigned_credit_customer', true)
                ->exists();

            if ($exists) {
                return;
            }

            DB::table('customers')->insert([
                'company_id' => $company->id,
                'branch_id' => null,
                'name' => 'Mteja wa Mkopo Ambaye Hajatajwa',
                'phone' => 'UNASSIGNED-CREDIT-'.$company->id,
                'email' => null,
                'address' => null,
                'region' => null,
                'district' => null,
                'customer_type' => 'credit',
                'credit_limit' => 0,
                'opening_balance' => 0,
                'balance_amount' => 0,
                'status' => 'active',
                'is_system_customer' => true,
                'is_unassigned_credit_customer' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
};
