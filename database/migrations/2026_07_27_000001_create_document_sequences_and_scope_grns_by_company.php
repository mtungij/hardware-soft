<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'document_type', 'year'],
                'document_sequences_company_type_year_unique'
            );
        });

        $this->backfillGoodsReceiptSequences();
        $this->dropGrnIndexIfExists('goods_receiving_notes_grn_number_unique');
        $this->createGrnIndexIfMissing(
            'goods_receiving_notes_company_grn_unique',
            ['company_id', 'grn_number']
        );
    }

    public function down(): void
    {
        $this->dropGrnIndexIfExists('goods_receiving_notes_company_grn_unique');
        $this->createGrnIndexIfMissing(
            'goods_receiving_notes_grn_number_unique',
            ['grn_number']
        );
        Schema::dropIfExists('document_sequences');
    }

    private function backfillGoodsReceiptSequences(): void
    {
        if (! Schema::hasTable('goods_receiving_notes')) {
            return;
        }

        $sequences = [];

        DB::table('goods_receiving_notes')
            ->whereNotNull('company_id')
            ->select(['company_id', 'grn_number'])
            ->orderBy('id')
            ->each(function (object $receipt) use (&$sequences): void {
                if (! preg_match('/^GRN-(\d{4})-(\d{6})$/', (string) $receipt->grn_number, $matches)) {
                    return;
                }

                $key = "{$receipt->company_id}:{$matches[1]}";
                $sequences[$key] = [
                    'company_id' => (int) $receipt->company_id,
                    'document_type' => 'goods_receipt',
                    'year' => (int) $matches[1],
                    'last_number' => max(
                        $sequences[$key]['last_number'] ?? 0,
                        (int) $matches[2]
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            });

        if ($sequences !== []) {
            DB::table('document_sequences')->insert(array_values($sequences));
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function createGrnIndexIfMissing(string $indexName, array $columns): void
    {
        if ($this->grnIndexExists($indexName)) {
            return;
        }

        $columnList = collect($columns)
            ->map(fn (string $column) => DB::getQueryGrammar()->wrap($column))
            ->join(', ');

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX {$indexName} ON goods_receiving_notes ({$columnList})");

            return;
        }

        DB::statement("ALTER TABLE goods_receiving_notes ADD UNIQUE {$indexName} ({$columnList})");
    }

    private function dropGrnIndexIfExists(string $indexName): void
    {
        if (! $this->grnIndexExists($indexName)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX {$indexName}");

            return;
        }

        DB::statement("ALTER TABLE goods_receiving_notes DROP INDEX {$indexName}");
    }

    private function grnIndexExists(string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select('PRAGMA index_list(goods_receiving_notes)'))
                ->contains(fn (object $index) => $index->name === $indexName);
        }

        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'goods_receiving_notes')
            ->where('index_name', $indexName)
            ->exists();
    }
};
