<?php

namespace App\Services;

use App\Models\PurchaseCostType;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseCostBreakdownService
{
    public const DEFAULT_TYPES = [
        'Product Cost', 'Transportation', 'Freight', 'Import Duty', 'Clearing',
        'Insurance', 'Loading / Offloading', 'Handling', 'Port Charges', 'Other',
    ];

    public function ensureDefaultTypes(int $companyId): void
    {
        $now = now();
        DB::table('purchase_cost_types')->insertOrIgnore(array_map(fn (string $name) => [
            'company_id' => $companyId,
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULT_TYPES));
    }

    /** @return array{rows: array<int, array<string, mixed>>, total_cents: int} */
    public function prepare(int $companyId, mixed $rawRows, string $field): array
    {
        if ($rawRows === null || $rawRows === []) {
            return ['rows' => [], 'total_cents' => 0];
        }
        if (! is_array($rawRows)) {
            throw ValidationException::withMessages([$field => __('application.cost_breakdown_must_be_a_list_06d6bae2')]);
        }
        $this->ensureDefaultTypes($companyId);
        $types = PurchaseCostType::query()->where('company_id', $companyId)->where('is_active', true)->get()->keyBy('id');
        $rows = [];
        $totalCents = 0;
        foreach ($rawRows as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('application.invalid_cost_component_1e089bbb')]);
            }
            if (blank($raw['type_id'] ?? null) && blank($raw['amount'] ?? null) && blank($raw['reference'] ?? null) && blank($raw['notes'] ?? null)) {
                continue;
            }
            $type = $types->get((int) ($raw['type_id'] ?? 0));
            if (! $type) {
                throw ValidationException::withMessages(["{$field}.{$index}.type_id" => __('application.select_an_active_cost_type_for_this_company_62c4571d')]);
            }
            $amount = trim((string) ($raw['amount'] ?? ''));
            if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                throw ValidationException::withMessages(["{$field}.{$index}.amount" => __('application.enter_an_amount_with_at_most_two_decimal_places_33b16eee')]);
            }
            $cents = (int) round((float) $amount * 100);
            $reference = trim((string) ($raw['reference'] ?? ''));
            $notes = trim((string) ($raw['notes'] ?? ''));
            if (mb_strlen($reference) > 255 || mb_strlen($notes) > 1000) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('application.reference_or_notes_are_too_long_a3b498de')]);
            }
            $rows[] = ['type' => $type, 'amount_cents' => $cents, 'reference' => $reference ?: null, 'notes' => $notes ?: null];
            $totalCents += $cents;
        }

        return ['rows' => $rows, 'total_cents' => $totalCents];
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function save(PurchaseItem $item, array $rows): void
    {
        foreach ($rows as $row) {
            $item->costBreakdown()->create([
                'company_id' => $item->company_id,
                'purchase_cost_type_id' => $row['type']->id,
                'cost_type_name_snapshot' => $row['type']->name,
                'amount' => $row['amount_cents'] / 100,
                'reference' => $row['reference'],
                'notes' => $row['notes'],
            ]);
        }
    }
}
