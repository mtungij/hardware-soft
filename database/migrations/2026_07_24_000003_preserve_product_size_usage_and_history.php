<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'uses_product_size')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('uses_product_size')->default(false)->after('product_size_id');
            });
        }

        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'supports_product_sizes')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('supports_product_sizes')->default(false)->after('allow_fractional_sales');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'uses_product_size')) {
            DB::table('products')
                ->whereNotNull('product_size_id')
                ->update(['uses_product_size' => true]);
        }

        if (Schema::hasTable('categories')
            && Schema::hasColumn('categories', 'supports_product_sizes')
            && Schema::hasTable('products')) {
            DB::table('categories')
                ->whereIn('id', DB::table('products')->whereNotNull('product_size_id')->select('category_id'))
                ->update(['supports_product_sizes' => true]);
        }

        $this->addHistoricalSizeReference('sale_items', 'product_id');
        $this->addHistoricalSizeReference('purchase_items', 'product_id');
    }

    public function down(): void
    {
        $this->dropHistoricalSizeReference('sale_items');
        $this->dropHistoricalSizeReference('purchase_items');

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'supports_product_sizes')) {
            Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('supports_product_sizes'));
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'uses_product_size')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('uses_product_size'));
        }
    }

    private function addHistoricalSizeReference(string $tableName, string $productColumn): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'product_size_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($productColumn): void {
            $table->foreignId('product_size_id')
                ->nullable()
                ->after($productColumn)
                ->constrained('product_sizes')
                ->restrictOnDelete();
        });

        DB::table($tableName)
            ->select(['id', $productColumn])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($tableName, $productColumn): void {
                $sizes = DB::table('products')
                    ->whereIn('id', $items->pluck($productColumn)->filter()->unique())
                    ->pluck('product_size_id', 'id');

                foreach ($items as $item) {
                    $sizeId = $sizes[$item->{$productColumn}] ?? null;

                    if ($sizeId) {
                        DB::table($tableName)->where('id', $item->id)->update(['product_size_id' => $sizeId]);
                    }
                }
            });
    }

    private function dropHistoricalSizeReference(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'product_size_id')) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('product_size_id'));
    }
};
