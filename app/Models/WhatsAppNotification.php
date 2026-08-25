<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'recipient_id', 'device_id', 'phone', 'notification_type', 'channel', 'category', 'message', 'attachment_path', 'attachment_type', 'status', 'attempts', 'available_at', 'queued_at', 'sent_at', 'failed_at', 'message_id', 'failure_reason', 'idempotency_key', 'metadata'])]
class WhatsAppNotification extends Model
{
    use HasCompany;

    protected $table = 'whatsapp_notifications';

    public const TERMINAL_STATUSES = ['sent', 'cancelled', 'suppressed'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(WhatsAppRecipient::class, 'recipient_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
