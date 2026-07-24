<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOLUME_UNITS = [
        ['code' => 'm3', 'name' => 'Cubic Metre', 'symbol' => 'm³', 'aliases' => ['m3', 'm³', 'cbm', 'cubic metre', 'cubic meter']],
        ['code' => 'l', 'name' => 'Litre', 'symbol' => 'L', 'aliases' => ['l', 'ltr', 'liter', 'litre', 'liters', 'litres']],
        ['code' => 'ml', 'name' => 'Millilitre', 'symbol' => 'ml', 'aliases' => ['ml', 'milliliter', 'millilitre', 'milliliters', 'millilitres']],
        ['code' => 'ft3', 'name' => 'Cubic Foot', 'symbol' => 'ft³', 'aliases' => ['ft3', 'ft³', 'cu ft', 'cubic foot', 'cubic feet']],
        ['code' => 'cm3', 'name' => 'Cubic Centimetre', 'symbol' => 'cm³', 'aliases' => ['cm3', 'cm³', 'cc', 'cubic centimetre', 'cubic centimeter']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('units') || ! Schema::hasTable('measurement_types')) {
            return;
        }

        if (! Schema::hasColumn('units', 'code')) {
            Schema::table('units', function (Blueprint $table): void {
                $table->string('code', 30)->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('units', 'measurement_type_id')) {
            Schema::table('units', function (Blueprint $table): void {
                $table->foreignId('measurement_type_id')
                    ->nullable()
                    ->after('code')
                    ->constrained('measurement_types')
                    ->restrictOnDelete();
            });
        }

        $measurementIds = DB::table('measurement_types')->pluck('id', 'code');

        foreach ($this->unitClassifications() as $measurementCode => $aliases) {
            if (! isset($measurementIds[$measurementCode])) {
                continue;
            }

            DB::table('units')->orderBy('id')->each(function (object $unit) use ($aliases, $measurementIds, $measurementCode): void {
                $labels = $this->normalizedLabels($unit);

                if (array_intersect($labels, $aliases) !== []) {
                    DB::table('units')->where('id', $unit->id)->update([
                        'measurement_type_id' => $measurementIds[$measurementCode],
                    ]);
                }
            });
        }

        if (! isset($measurementIds['volume']) || ! Schema::hasTable('companies')) {
            return;
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::VOLUME_UNITS as $definition) {
                $this->upsertVolumeUnit((int) $companyId, (int) $measurementIds['volume'], $definition);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('units')) {
            return;
        }

        if (Schema::hasColumn('units', 'measurement_type_id')) {
            Schema::table('units', fn (Blueprint $table) => $table->dropConstrainedForeignId('measurement_type_id'));
        }

        if (Schema::hasColumn('units', 'code')) {
            Schema::table('units', fn (Blueprint $table) => $table->dropColumn('code'));
        }
    }

    /**
     * @param  array{code: string, name: string, symbol: string, aliases: array<int, string>}  $definition
     */
    private function upsertVolumeUnit(int $companyId, int $measurementTypeId, array $definition): void
    {
        $units = DB::table('units')->where('company_id', $companyId)->orderBy('id')->get();
        $unit = $units->first(function (object $unit) use ($definition): bool {
            return in_array(mb_strtolower(trim((string) ($unit->code ?? ''))), $definition['aliases'], true)
                || array_intersect($this->normalizedLabels($unit), $definition['aliases']) !== [];
        });

        if (! $unit) {
            DB::table('units')->insert([
                'company_id' => $companyId,
                'name' => $definition['name'],
                'code' => $definition['code'],
                'measurement_type_id' => $measurementTypeId,
                'short_name' => $definition['symbol'],
                'description' => $definition['name'].' volume inventory unit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $symbolConflict = $units->contains(fn (object $candidate): bool => $candidate->id !== $unit->id
            && mb_strtolower(trim((string) $candidate->short_name)) === mb_strtolower($definition['symbol']));

        DB::table('units')->where('id', $unit->id)->update([
            'name' => $definition['name'],
            'code' => $definition['code'],
            'measurement_type_id' => $measurementTypeId,
            'short_name' => $symbolConflict ? $unit->short_name : $definition['symbol'],
            'status' => 'active',
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function unitClassifications(): array
    {
        return [
            'weight' => ['kg', 'kgs', 'kilogram', 'kilograms', 'g', 'gram', 'grams', 'ton', 'tons', 'tonne', 'tonnes'],
            'length' => ['m', 'metre', 'metres', 'meter', 'meters', 'ft', 'foot', 'feet'],
            'volume' => collect(self::VOLUME_UNITS)->flatMap(fn (array $unit) => $unit['aliases'])->unique()->values()->all(),
            'area' => ['m²', 'm2', 'sqm', 'square metre', 'square meter', 'ft²', 'ft2', 'sq ft', 'square foot', 'square feet'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizedLabels(object $unit): array
    {
        return array_values(array_unique(array_filter([
            mb_strtolower(trim((string) ($unit->name ?? ''))),
            mb_strtolower(trim((string) ($unit->short_name ?? ''))),
            mb_strtolower(trim((string) ($unit->code ?? ''))),
        ])));
    }
};
