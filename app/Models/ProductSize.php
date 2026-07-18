<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'symbol', 'description', 'status'])]
class ProductSize extends Model
{
    use HasCompany, HasFactory;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function label(): string
    {
        return $this->symbol ?: $this->name;
    }
}
