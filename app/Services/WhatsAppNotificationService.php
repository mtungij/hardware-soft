<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Scopes\BranchScope;
use App\Models\Scopes\CompanyScope;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Support\WhatsAppCategories;
use App\Support\WhatsAppPhone;
use Illuminate\Support\Facades\Cache;
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
        if (! WhatsAppCategories::allows($company->id, $category)) {
            return [];
        }

        $setting = CompanyWhatsAppSetting::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->first();

        if (! $setting?->enabled || ! $setting->categoryEnabled($category)) {
            return [];
        }

        // Delivery follows the event's tenant and recipient subscriptions, including
        // company-wide recipients, rather than the acting staff member's branch.
        $recipients = WhatsAppRecipient::withoutGlobalScopes([CompanyScope::class, BranchScope::class])
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
        $setting = CompanyWhatsAppSetting::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->firstOrFail();

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
        ?string $attachmentPath = null,
        ?string $attachmentType = null,
        bool $sensitive = false,
        ?string $idempotencyRecipientToken = null,
    ): WhatsAppNotification {
        return $this->create(
            $company,
            $setting,
            $phone,
            $notificationType,
            $category,
            $eventKey,
            $sensitive ? WhatsAppNotification::SENSITIVE_MESSAGE_PLACEHOLDER : $message,
            $branchId,
            attachmentPath: $attachmentPath,
            attachmentType: $attachmentType,
            metadata: $metadata,
            encryptedMessage: $sensitive ? encrypt($message) : null,
            idempotencyRecipientToken: $idempotencyRecipientToken,
        );
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

    public function retry(WhatsAppNotification $notification): bool
    {
        return DB::transaction(function () use ($notification): bool {
            $notification = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $notification->company_id)->lockForUpdate()->findOrFail($notification->id);
            if (in_array($notification->status, ['sent', 'sending', 'cancelled'], true)) {
                return false;
            }

            if (! WhatsAppCategories::allows($notification->company_id, $notification->category)) {
                $notification->update(['status' => 'suppressed', 'failure_reason' => 'Company manufacturing module is disabled.']);

                return false;
            }

            if ($notification->status === 'queued') {
                $ids = app(WhatsAppQueueInspector::class)->pendingNotificationIds();
                if ($ids === null || in_array($notification->id, $ids, true)) {
                    return false;
                }
            }

            $notification->update([
                'status' => 'queued',
                'failure_reason' => null,
                'failed_at' => null,
                'available_at' => now(),
                'queued_at' => now(),
            ]);

            SendWhatsAppNotification::dispatch($notification->id)->onQueue('whatsapp')->afterCommit();

            return true;
        });
    }

    public function resumePending(WhatsAppNotification $notification): void
    {
        DB::transaction(function () use ($notification): void {
            $notification = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $notification->company_id)->lockForUpdate()->findOrFail($notification->id);
            if ($notification->status !== 'pending' || $notification->available_at?->isFuture()) {
                return;
            }
            $ids = app(WhatsAppQueueInspector::class)->pendingNotificationIds();
            if ($ids === null || in_array($notification->id, $ids, true)) {
                return;
            }
            $this->retry($notification);
        });
    }

    public function recoverQueued(int $companyId, int $notificationId, bool $dispatch = false): string
    {
        return DB::transaction(function () use ($companyId, $notificationId, $dispatch): string {
            $notification = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($notificationId);
            if (! $notification || $notification->status !== 'queued' || $notification->attempts !== 0
                || $notification->notification_type === 'debug_after_commit'
                || ! $notification->queued_at || $notification->queued_at->gt(now()->subMinutes(5))
                || $notification->created_at->lt(now()->subHour()) || $notification->available_at?->isFuture()) {
                return 'ineligible';
            }
            $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
            if (! $setting?->enabled || $setting->sending_paused || $setting->last_device_state !== 'logged_in'
                || blank($setting->device_id) || $setting->device_id !== $notification->device_id
                || ! $setting->categoryEnabled($notification->category)
                || ! WhatsAppCategories::allows($companyId, $notification->category)) {
                return 'ineligible';
            }
            if ($notification->recipient_id) {
                $recipient = WhatsAppRecipient::withoutGlobalScopes()->where('company_id', $companyId)->find($notification->recipient_id);
                if (! $recipient?->accepts($notification->category, $notification->branch_id) || $recipient->phone !== $notification->phone) {
                    return 'ineligible';
                }
            }
            $ids = app(WhatsAppQueueInspector::class)->pendingNotificationIds();
            if ($ids === null) {
                return 'unverified';
            }
            if (in_array($notification->id, $ids, true)) {
                return 'job-exists';
            }
            if (! $dispatch) {
                return 'eligible';
            }

            // Refresh the eligibility timestamp while holding the row lock. A
            // concurrent recovery cannot dispatch again after this commit.
            return $this->retry($notification) ? 'dispatched' : 'unverified';
        });
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
        ?string $encryptedMessage = null,
        ?string $idempotencyRecipientToken = null,
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

        if (! WhatsAppCategories::allows($company->id, $category)) {
            $suppression = 'Company manufacturing module is disabled.';
        }

        $recipientToken = $idempotencyRecipientToken ?: ($recipient?->id ?: hash('sha256', $phone));
        $idempotencyKey = substr($eventKey.':recipient:'.$recipientToken, 0, 191);

        $notification = WhatsAppNotification::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
            ['company_id' => $company->id, 'idempotency_key' => $idempotencyKey],
            [
                'branch_id' => $branchId,
                'recipient_id' => $recipient?->id,
                'device_id' => $setting->device_id,
                'phone' => $phone,
                'notification_type' => $notificationType,
                'category' => $category,
                'message' => $message,
                'encrypted_message' => $encryptedMessage,
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
            $lock = Cache::lock("whatsapp-notification-dispatch:{$notification->id}", 30);

            if ($lock->get()) {
                SendWhatsAppNotification::dispatch($notification->id)
                    ->onQueue('whatsapp')
                    ->afterCommit();
            }
        }

        return $notification;
    }
}
