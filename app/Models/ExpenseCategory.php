<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'name', 'description', 'status'])]
class ExpenseCategory extends Model
{
    use HasCompany, HasFactory;

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::squish($value));
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
