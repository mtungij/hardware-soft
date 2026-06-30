<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'company_id')) {
            return;
        }

        $this->dropIndexIfExists('sales_sale_number_unique');
        $this->createUniqueIndexIfMissing('sales_company_sale_number_unique', ['company_id', 'sale_number']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        $this->dropIndexIfExists('sales_company_sale_number_unique');
        $this->createUniqueIndexIfMissing('sales_sale_number_unique', ['sale_number']);
    }

    private function dropIndexIfExists(string $indexName): void
    {
        if (! $this->indexExists($indexName)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX {$indexName}");

            return;
        }

        DB::statement("ALTER TABLE sales DROP INDEX {$indexName}");
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function createUniqueIndexIfMissing(string $indexName, array $columns): void
    {
        if ($this->indexExists($indexName)) {
            return;
        }

        $columnList = collect($columns)
            ->map(fn (string $column) => DB::getQueryGrammar()->wrap($column))
            ->join(', ');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX {$indexName} ON sales ({$columnList})");

            return;
        }

        DB::statement("ALTER TABLE sales ADD UNIQUE {$indexName} ({$columnList})");
    }

    private function indexExists(string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select('PRAGMA index_list(sales)'))
                ->contains(fn (object $index) => $index->name === $indexName);
        }

        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'sales')
            ->where('index_name', $indexName)
            ->exists();
    }
};
