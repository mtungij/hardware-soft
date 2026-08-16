<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['company_id', 'customer_material_account_id', 'action', 'subject_type', 'subject_id', 'old_values', 'new_values', 'reason', 'actor_id'])]
class CustomerMaterialAudit extends Model
{
    use HasCompany;

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialAccount::class, 'customer_material_account_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }
}
