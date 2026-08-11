<?php

namespace App\Livewire\Concerns;

trait ManagesProductUnitConversionRows
{
    public function initializeManagesProductUnitConversionRows(): void
    {
        $this->normalizeUnitConversionRows();
    }

    public function updatedUnitConversions(mixed $value, ?string $key = null): void
    {
        if ($key === null && ! is_array($value)) {
            $this->unit_conversions = [];
        }
    }

    protected function appendUnitConversionRow(): void
    {
        $this->normalizeUnitConversionRows();

        $this->unit_conversions[] = [
            'unit_id' => null,
            'conversion_factor' => null,
            'retail_price' => null,
            'wholesale_price' => null,
            'purchase_price' => null,
            'can_purchase' => false,
            'can_sell' => true,
            'active' => true,
        ];
    }

    protected function removeUnitConversionRow(int $index): void
    {
        $this->normalizeUnitConversionRows();

        if (! array_key_exists($index, $this->unit_conversions)) {
            return;
        }

        unset($this->unit_conversions[$index]);
        $this->unit_conversions = array_values($this->unit_conversions);
        $this->resetErrorBag('unit_conversions');
    }

    public function addUnitConversion(): void
    {
        $this->appendUnitConversionRow();
    }

    public function removeUnitConversion(int $index): void
    {
        $this->removeUnitConversionRow($index);
    }

    private function normalizeUnitConversionRows(): void
    {
        if (! is_array($this->unit_conversions ?? null)) {
            $this->unit_conversions = [];
        }
    }
}
