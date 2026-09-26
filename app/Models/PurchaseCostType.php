<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'name', 'is_active'])]
class PurchaseCostType extends Model
{
    use HasCompany;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
