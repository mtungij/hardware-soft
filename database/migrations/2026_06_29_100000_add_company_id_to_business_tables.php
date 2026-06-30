<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'users',
        'branches',
        'settings',
        'categories',
        'units',
        'products',
        'suppliers',
        'customers',
        'stock_locations',
        'purchases',
        'purchase_items',
        'goods_receiving_notes',
        'goods_receiving_note_items',
        'stock_movements',
        'stock_adjustments',
        'stock_transfers',
        'stock_transfer_items',
        'sales',
        'sale_items',
        'sale_payments',
        'expense_categories',
        'expenses',
        'customer_payments',
        'supplier_payments',
        'cashbook_sessions',
        'purchase_email_logs',
        'customer_accounts',
        'customer_receipts',
        'customer_deposits',
        'customer_deposit_usages',
        'customer_notifications',
        'announcements',
        'announcement_customers',
        'customer_messages',
        'message_templates',
        'reports',
        'purchase_orders',
        'notifications',
        'email_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        $companyId = $this->defaultCompanyId();

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            DB::table($tableName)
                ->whereNull('company_id')
                ->update(['company_id' => $companyId]);
        }

        $this->replaceGlobalUniques();
    }

    public function down(): void
    {
        $this->dropCompanyUniques();

        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }

    private function defaultCompanyId(): int
    {
        if (! Schema::hasTable('companies')) {
            return 1;
        }

        $existingId = DB::table('companies')->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) Company::query()->create([
            'company_name' => config('app.name', 'Hardex POS'),
            'business_type' => 'Hardware Store',
            'phone' => '+255 700 000 000',
            'whatsapp_number' => '+255 700 000 000',
            'country' => 'Tanzania',
            'currency' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'language' => 'sw',
        ])->getKey();
    }

    private function replaceGlobalUniques(): void
    {
        $indexes = [
            'branches' => [
                ['columns' => ['code'], 'name' => 'branches_code_unique'],
            ],
            'categories' => [
                ['columns' => ['code'], 'name' => 'categories_code_unique'],
            ],
            'units' => [
                ['columns' => ['short_name'], 'name' => 'units_short_name_unique'],
            ],
            'products' => [
                ['columns' => ['sku'], 'name' => 'products_sku_unique'],
                ['columns' => ['barcode'], 'name' => 'products_barcode_unique'],
            ],
        ];

        foreach ($indexes as $tableName => $tableIndexes) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableIndexes, $tableName): void {
                foreach ($tableIndexes as $index) {
                    try {
                        $table->dropUnique($index['name']);
                    } catch (Throwable) {
                        try {
                            $table->dropUnique($index['columns']);
                        } catch (Throwable) {
                            //
                        }
                    }

                    $table->unique(['company_id', ...$index['columns']], "{$tableName}_company_{$index['columns'][0]}_unique");
                }
            });
        }
    }

    private function dropCompanyUniques(): void
    {
        $indexes = [
            'branches' => ['code'],
            'categories' => ['code'],
            'units' => ['short_name'],
            'products' => ['sku', 'barcode'],
        ];

        foreach ($indexes as $tableName => $columns) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName): void {
                foreach ($columns as $column) {
                    try {
                        $table->dropUnique("{$tableName}_company_{$column}_unique");
                    } catch (Throwable) {
                        //
                    }
                }
            });
        }
    }
};
