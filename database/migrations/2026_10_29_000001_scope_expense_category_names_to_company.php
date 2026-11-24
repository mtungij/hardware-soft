<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = Schema::getIndexes('expense_categories');
        $hasCompanyUnique = collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === ['company_id', 'name']);

        if (! $hasCompanyUnique) {
            $duplicates = DB::table('expense_categories')->whereNotNull('company_id')
                ->select('company_id', 'name')->groupBy('company_id', 'name')->havingRaw('COUNT(*) > 1')->exists();
            if ($duplicates) {
                throw new RuntimeException('Duplicate expense-category names within a company require manual review. No records were changed.');
            }

            // Add protection before removing the global constraint; never rewrite records.
            Schema::table('expense_categories', fn (Blueprint $table) => $table->unique(['company_id', 'name'], 'expense_categories_company_name_unique'));
        }

        foreach ($indexes as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === ['name']) {
                Schema::table('expense_categories', fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
    }

    public function down(): void
    {
        // Forward-only: restoring global uniqueness could invalidate legitimate tenant data.
    }
};
