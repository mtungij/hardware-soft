<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('measurement_types')) {
            return;
        }

        DB::table('measurement_types')->updateOrInsert(
            ['code' => 'other'],
            ['name' => 'Other', 'sort_order' => 6],
        );

        if (! Schema::hasTable('units') || ! Schema::hasColumn('units', 'measurement_type_id')) {
            return;
        }

        $measurementIds = DB::table('measurement_types')->pluck('id', 'code');

        DB::table('units')->orderBy('id')->each(function (object $unit) use ($measurementIds): void {
            if ($unit->measurement_type_id !== null) {
                return;
            }

            $measurementCode = $this->classify($unit);

            DB::table('units')->where('id', $unit->id)->update([
                'measurement_type_id' => $measurementIds[$measurementCode] ?? $measurementIds['other'],
            ]);
        });
    }

    public function down(): void
    {
        // Keep unit classifications and the shared lookup value to avoid invalidating assigned records.
    }

    private function classify(object $unit): string
    {
        $label = mb_strtolower(trim(
            ($unit->name ?? '').' '.($unit->short_name ?? '').' '.($unit->code ?? '')
        ));

        return match (true) {
            (bool) preg_match('/\b(kg|kgs|kilogram|kilograms|gram|grams|ton|tons|tonne|tonnes)\b/u', $label) => 'weight',
            str_contains($label, 'm³'), str_contains($label, 'ft³'), str_contains($label, 'cm³'),
            (bool) preg_match('/\b(m3|ft3|cm3|cbm|litre|liter|litres|liters|ltr|ml|millilitre|milliliter|cubic)\b/u', $label) => 'volume',
            str_contains($label, 'm²'), str_contains($label, 'ft²'),
            (bool) preg_match('/\b(m2|ft2|sqm|square)\b/u', $label) => 'area',
            (bool) preg_match('/\b(m|metre|metres|meter|meters|cm|centimetre|centimeter|mm|millimetre|millimeter|ft|foot|feet|in|inch|inches)\b/u', $label) => 'length',
            (bool) preg_match('/\b(pc|pcs|piece|pieces|bag|bags|box|boxes|roll|rolls|pack|packs|bundle|bundles|sheet|sheets|trip|trips|bottle|bottles|set|sets|pair|pairs|bucket|buckets)\b/u', $label) => 'count',
            default => 'other',
        };
    }
};
