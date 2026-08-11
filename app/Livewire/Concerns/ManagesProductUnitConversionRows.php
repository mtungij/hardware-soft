<?php

namespace App\Livewire\Concerns;

trait ManagesProductUnitConversionRows
{
    public function addUnitConversion(): void
    {
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

    public function removeUnitConversion(int $index): void
    {
        if (! array_key_exists($index, $this->unit_conversions)) {
            return;
        }

        unset($this->unit_conversions[$index]);
        $this->unit_conversions = array_values($this->unit_conversions);
        $this->resetErrorBag('unit_conversions');
    }
}
