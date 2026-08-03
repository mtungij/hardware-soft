<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'machine_id', 'production_mould_id', 'current_machine_id', 'current_mould_id',
    'installed_at', 'removed_at', 'removal_reason', 'notes', 'installed_by', 'removed_by',
])]
class ProductionMouldInstallation extends Model
{
    use HasCompany;

    public const REASON_REMOVED = 'removed';

    public const REASON_REPLACED = 'replaced';

    public const REASON_MAINTENANCE = 'maintenance';

    protected function casts(): array
    {
        return ['installed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('current_machine_id'));
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mould(): BelongsTo
    {
        return $this->belongsTo(ProductionMould::class, 'production_mould_id');
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
