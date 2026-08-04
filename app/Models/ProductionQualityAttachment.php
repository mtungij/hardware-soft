<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'production_quality_inspection_id', 'category', 'original_name', 'storage_disk', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by', 'uploaded_at'])]
class ProductionQualityAttachment extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quality evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Quality evidence cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'production_quality_inspection_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
