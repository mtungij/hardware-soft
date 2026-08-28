<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Support\WhatsAppPhone;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsAppNotificationService
{
    /**
     * @param  string|callable(WhatsAppRecipient): string  $message
     * @param  null|callable(WhatsAppRecipient): bool  $recipientFilter
     * @return array<int, WhatsAppNotification>
     */
    public function queueForRecipients(
        Company $company,
        string $category,
        string $notificationType,
        string $eventKey,
        string|callable $message,
        ?int $branchId = null,
        ?string $attachmentPath = null,
        ?string $attachmentType = null,
        array $metadata = [],
        ?callable $recipientFilter = null,
    ): array {
        $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $company->id)->first();

        if (! $setting?->enabled || ! $setting->categoryEnabled($category)) {
            return [];
        }

        $recipients = WhatsAppRecipient::withoutGlobalScopes()
            ->with(['user' => fn ($query) => $query->withoutGlobalScopes()->with('roles')])
            ->where('company_id', $company->id)
            ->where('active', true)
            ->get()
            ->filter(fn (WhatsAppRecipient $recipient): bool => $recipient->accepts($category, $branchId));

        if ($recipientFilter) {
            $recipients = $recipients->filter($recipientFilter);
        }

        return $recipients->map(function (WhatsAppRecipient $recipient) use ($setting, $company, $branchId, $notificationType, $category, $eventKey, $message, $attachmentPath, $attachmentType, $metadata): WhatsAppNotification {
            return $this->create(
                company: $company,
                setting: $setting,
                phone: $recipient->phone,
                notificationType: $notificationType,
                category: $category,
                eventKey: $eventKey,
                message: is_callable($message) ? $message($recipient) : $message,
                branchId: $branchId,
                recipient: $recipient,
                attachmentPath: $attachmentPath,
                attachmentType: $attachmentType,
                metadata: $metadata,
            );
        })->values()->all();
    }

    public function queueTest(Company $company, string $phone, string $message): WhatsAppNotification
    {
        $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

        return $this->create($company, $setting, $phone, 'test_message', 'system', 'test:'.now()->format('YmdHis'), $message);
    }

    public function queueRecipient(
        Company $company,
        CompanyWhatsAppSetting $setting,
        WhatsAppRecipient $recipient,
        string $category,
        string $notificationType,
        string $eventKey,
        string $message,
        ?int $branchId = null,
        ?string $attachmentPath = null,
        ?string $attachmentType = null,
        array $metadata = [],
    ): WhatsAppNotification {
        return $this->create($company, $setting, $recipient->phone, $notificationType, $category, $eventKey, $message, $branchId, $recipient, $attachmentPath, $attachmentType, $metadata);
    }

    public function queuePhone(
        Company $company,
        CompanyWhatsAppSetting $setting,
        string $phone,
        string $category,
        string $notificationType,
        string $eventKey,
        string $message,
        ?int $branchId = null,
        array $metadata = [],
    ): WhatsAppNotification {
        return $this->create($company, $setting, $phone, $notificationType, $category, $eventKey, $message, $branchId, metadata: $metadata);
    }

    public function afterCommit(callable $callback): void
    {
        DB::afterCommit(function () use ($callback): void {
            try {
                $callback($this);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function retry(WhatsAppNotification $notification): void
    {
        if (in_array($notification->status, ['sent', 'sending'], true)) {
            return;
        }

        $notification->update([
            'status' => 'queued',
            'failure_reason' => null,
            'failed_at' => null,
            'available_at' => now(),
            'queued_at' => now(),
        ]);

        SendWhatsAppNotification::dispatch($notification->id)->onQueue('whatsapp')->afterCommit();
    }

    private function create(
        Company $company,
        CompanyWhatsAppSetting $setting,
        string $phone,
        string $notificationType,
        string $category,
        string $eventKey,
        string $message,
        ?int $branchId = null,
        ?WhatsAppRecipient $recipient = null,
        ?string $attachmentPath = null,
        ?string $attachmentType = null,
        array $metadata = [],
    ): WhatsAppNotification {
        try {
            $phone = WhatsAppPhone::normalize($phone);
            $suppression = blank($setting->device_id)
                ? 'Company WhatsApp Device ID is not configured.'
                : ($setting->sending_paused ? 'WhatsApp sending is paused for this company.' : null);
        } catch (Throwable $exception) {
            $phone = preg_replace('/\D+/', '', $phone) ?: 'invalid';
            $suppression = $exception->getMessage();
        }

        $recipientToken = $recipient?->id ?: hash('sha256', $phone);
        $idempotencyKey = substr($eventKey.':recipient:'.$recipientToken, 0, 191);

        $notification = WhatsAppNotification::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'idempotency_key' => $idempotencyKey],
            [
                'branch_id' => $branchId,
                'recipient_id' => $recipient?->id,
                'device_id' => $setting->device_id,
                'phone' => $phone,
                'notification_type' => $notificationType,
                'category' => $category,
                'message' => $message,
                'attachment_path' => $attachmentPath,
                'attachment_type' => $attachmentType,
                'status' => $suppression ? 'suppressed' : 'queued',
                'failure_reason' => $suppression,
                'available_at' => now(),
                'queued_at' => $suppression ? null : now(),
                'metadata' => $metadata,
            ]
        );

        if ($notification->wasRecentlyCreated && $notification->status === 'queued') {
            SendWhatsAppNotification::dispatch($notification->id)->onQueue('whatsapp')->afterCommit();
        }

        return $notification;
    }
}
