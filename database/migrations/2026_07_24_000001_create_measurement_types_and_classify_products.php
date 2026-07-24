<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPES = [
        ['code' => 'count', 'name' => 'Count', 'sort_order' => 1],
        ['code' => 'length', 'name' => 'Length', 'sort_order' => 2],
        ['code' => 'weight', 'name' => 'Weight', 'sort_order' => 3],
        ['code' => 'area', 'name' => 'Area', 'sort_order' => 4],
        ['code' => 'volume', 'name' => 'Volume', 'sort_order' => 5],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('measurement_types')) {
            Schema::create('measurement_types', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name', 60);
                $table->unsignedTinyInteger('sort_order')->default(0);
            });
        }

        foreach (self::TYPES as $type) {
            DB::table('measurement_types')->updateOrInsert(
                ['code' => $type['code']],
                ['name' => $type['name'], 'sort_order' => $type['sort_order']],
            );
        }

        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'measurement_type_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('measurement_type_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('measurement_types')
                    ->restrictOnDelete();
            });
        }

        $ids = DB::table('measurement_types')->pluck('id', 'code');
        $products = DB::table('products')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select([
                'products.id',
                'products.product_size_id',
                'products.conversion_factor',
                'products.allow_fractional_sale',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
            ])
            ->whereNull('products.measurement_type_id')
            ->get();

        foreach ($products as $product) {
            $code = $this->classify($product);

            DB::table('products')->where('id', $product->id)->update([
                'measurement_type_id' => $ids[$code],
                'allow_fractional_sale' => $code === 'count' ? false : (bool) $product->allow_fractional_sale,
                'conversion_factor' => $code === 'length' ? max(0.0001, (float) ($product->conversion_factor ?: 1)) : 1,
            ]);
        }

        DB::table('products')
            ->whereNull('measurement_type_id')
            ->update(['measurement_type_id' => $ids['count']]);
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'measurement_type_id')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('measurement_type_id'));
        }

        Schema::dropIfExists('measurement_types');
    }

    private function classify(object $product): string
    {
        $unit = strtolower(trim(($product->unit_name ?? '').' '.($product->unit_short_name ?? '')));
        $normalized = str_replace(['²', '³', '^2', '^3'], ['2', '3', '2', '3'], $unit);

        if (preg_match('/\b(m2|sqm|square metre|square meter)\b/', $normalized)) {
            return 'area';
        }

        if (preg_match('/\b(m3|cbm|cubic metre|cubic meter)\b/', $normalized)) {
            return 'volume';
        }

        if (preg_match('/\b(kg|kgs|kilogram|kilograms|gram|grams|g)\b/', $normalized)) {
            return 'weight';
        }

        if ($product->product_size_id !== null
            || (float) ($product->conversion_factor ?? 1) !== 1.0
            || preg_match('/\b(m|metre|metres|meter|meters|foot|feet|ft)\b/', $normalized)
            || (bool) $product->allow_fractional_sale) {
            return 'length';
        }

        return 'count';
    }
};
