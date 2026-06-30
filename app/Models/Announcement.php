<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use HasCompany;

    protected $fillable = [
        'company_id',
        'title',
        'message',
        'image',
        'attachment',
        'priority',
        'visibility_type',
        'publish_date',
        'expiry_date',
        'status',
        'target_filters',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'publish_date' => 'datetime',
            'expiry_date' => 'datetime',
            'target_filters' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementCustomer::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'announcement_customers')
            ->withPivot(['customer_account_id', 'is_delivered', 'delivered_at', 'is_read', 'read_at'])
            ->withTimestamps();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class, 'notifiable_id')
            ->where('notifiable_type', self::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
