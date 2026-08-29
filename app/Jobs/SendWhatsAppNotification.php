<?php

namespace App\Jobs;

use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppNotification;
use App\Services\Gowa;
use App\Services\WhatsAppDebtPdfService;
use App\Services\WhatsAppDebtReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $notificationId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function middleware(): array
    {
        $deviceId = WhatsAppNotification::withoutGlobalScopes()->whereKey($this->notificationId)->value('device_id') ?: 'missing';

        return [(new WithoutOverlapping('whatsapp-device:'.hash('sha256', $deviceId)))->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(Gowa $gowa): void
    {
        $notification = WhatsAppNotification::withoutGlobalScopes()->find($this->notificationId);

        if (! $notification || in_array($notification->status, WhatsAppNotification::TERMINAL_STATUSES, true)) {
            return;
        }

        $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $notification->company_id)->first();

        if (! $setting || ! $setting->enabled || $setting->sending_paused || blank($setting->device_id) || $setting->device_id !== $notification->device_id) {
            $this->suppress($notification, 'Company WhatsApp settings are disabled, paused, missing, or no longer match this notification.');

            return;
        }

        if ($setting->last_device_state !== 'logged_in') {
            $notification->update(['status' => 'pending', 'failure_reason' => 'WhatsApp device is not connected.']);

            return;
        }

        if ($delay = $this->quietHoursDelay($setting)) {
            $notification->update(['status' => 'pending', 'available_at' => now()->addSeconds($delay)]);
            $this->release($delay);

            return;
        }

        if ($delay = $this->throttleDelay($setting)) {
            $notification->update(['status' => 'queued', 'available_at' => now()->addSeconds($delay)]);
            $this->release($delay);

            return;
        }

        if (! $this->revalidateDebt($notification, $setting)) {
            return;
        }

        $numberCacheKey = 'whatsapp:number:'.hash('sha256', $setting->device_id.'|'.$notification->phone);
        $isOnWhatsApp = Cache::remember($numberCacheKey, (int) config('gowa.number_check_ttl', 86400), fn (): bool => $gowa->isOnWhatsApp($setting->device_id, $notification->phone));

        if (! $isOnWhatsApp) {
            $this->suppress($notification, 'Recipient number is not registered on WhatsApp.');

            return;
        }

        $notification->update(['status' => 'sending', 'attempts' => $notification->attempts + 1, 'failure_reason' => null]);

        try {
            $message = $notification->resolvedDeliveryMessage();
            $response = match ($notification->attachment_type) {
                'file' => $gowa->sendFile($setting->device_id, $notification->phone, $this->attachmentPath($notification), $message),
                'image' => $gowa->sendImage($setting->device_id, $notification->phone, $this->attachmentPath($notification), $message),
                default => $gowa->sendText($setting->device_id, $notification->phone, $message),
            };

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'message_id' => data_get($response, 'results.message_id'),
                'failed_at' => null,
            ]);
            Cache::put('whatsapp:last-send:'.hash('sha256', $setting->device_id), now()->timestamp, 3600);
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            $notification->update(['status' => 'queued', 'failure_reason' => $this->safeFailure($exception)]);

            if ($status === 429 || $status === null || $status >= 500) {
                throw $exception;
            }

            $notification->update(['status' => 'failed', 'failed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        WhatsAppNotification::withoutGlobalScopes()->whereKey($this->notificationId)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $exception ? $this->safeFailure($exception) : 'WhatsApp delivery exhausted all retries.',
        ]);
    }

    private function quietHoursDelay(CompanyWhatsAppSetting $setting): ?int
    {
        if (! $setting->quiet_hours_start || ! $setting->quiet_hours_end) {
            return null;
        }

        $now = CarbonImmutable::now($setting->timezone);
        $start = $now->setTimeFromTimeString($setting->quiet_hours_start);
        $end = $now->setTimeFromTimeString($setting->quiet_hours_end);

        if ($start->equalTo($end)) {
            return null;
        }

        if ($start->lessThan($end)) {
            return $now->betweenIncluded($start, $end) ? max(1, $now->diffInSeconds($end)) : null;
        }

        if ($now->greaterThanOrEqualTo($start)) {
            return max(1, $now->diffInSeconds($end->addDay()));
        }

        return $now->lessThanOrEqualTo($end) ? max(1, $now->diffInSeconds($end)) : null;
    }

    private function throttleDelay(CompanyWhatsAppSetting $setting): ?int
    {
        $deviceKey = hash('sha256', $setting->device_id);
        $lastSend = (int) Cache::get('whatsapp:last-send:'.$deviceKey, 0);
        $minimum = max(1, (int) $setting->minimum_send_interval_seconds);

        if ($lastSend && now()->timestamp - $lastSend < $minimum) {
            return $minimum - (now()->timestamp - $lastSend);
        }

        $minuteKey = 'whatsapp:minute:'.$deviceKey;
        $hourKey = 'whatsapp:hour:'.$deviceKey;

        if (RateLimiter::tooManyAttempts($minuteKey, max(1, (int) $setting->maximum_messages_per_minute))) {
            return max(1, RateLimiter::availableIn($minuteKey));
        }

        if (RateLimiter::tooManyAttempts($hourKey, max(1, (int) $setting->maximum_messages_per_hour))) {
            return max(1, RateLimiter::availableIn($hourKey));
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        return null;
    }

    private function attachmentPath(WhatsAppNotification $notification): string
    {
        $path = $notification->attachment_path;

        if (blank($path)) {
            throw new RuntimeException('WhatsApp attachment path is missing.');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : Storage::disk('local')->path($path);
    }

    private function revalidateDebt(WhatsAppNotification $notification, CompanyWhatsAppSetting $setting): bool
    {
        if (! str_starts_with($notification->notification_type, 'debt_') && $notification->notification_type !== 'management_debt_summary') {
            return true;
        }

        if (! $setting->debt_reminders_enabled || ! $setting->categoryEnabled('customer_debt')) {
            $this->suppress($notification, 'Debt reminders were disabled before delivery.');

            return false;
        }

        $debts = app(WhatsAppDebtReminderService::class);
        $company = $setting->company()->withoutGlobalScopes()->first();
        if (! $company) {
            $this->suppress($notification, 'Debt reminder company no longer exists.');

            return false;
        }
        $setting->setRelation('company', $company);

        if (str_starts_with($notification->notification_type, 'debt_')) {
            $message = $debts->revalidateCustomerNotification($notification, $setting);
            if ($message === null) {
                $this->suppress($notification, 'Debt was settled, disabled, or its due state changed before delivery.');

                return false;
            }
            if ($message !== $notification->message) {
                $notification->update(['message' => $message]);
            }
        }

        if ($notification->notification_type === 'management_debt_summary') {
            $recipient = $notification->recipient()->with(['user.roles', 'branch'])->first();
            if (! $recipient || ! $debts->canReceiveManagementSummary($recipient->user)) {
                $this->suppress($notification, 'Management recipient no longer has receivables permission.');

                return false;
            }
            $date = CarbonImmutable::parse(data_get($notification->metadata, 'summary_date'), $setting->timezone);
            $rows = $debts->enabledRows($debts->managementDebts($company, $recipient, $date), $setting, $date);
            if ($rows->isEmpty()) {
                $this->suppress($notification, 'All debts in this management summary were settled before delivery.');

                return false;
            }
            $updates = ['message' => $debts->managementMessage($rows, $date)];
            if ($notification->attachment_type === 'file') {
                $updates['attachment_path'] = app(WhatsAppDebtPdfService::class)->generate($company, $recipient, $date, $rows);
            }
            $notification->update($updates);
        }

        return true;
    }

    private function suppress(WhatsAppNotification $notification, string $reason): void
    {
        $notification->update(['status' => 'suppressed', 'failure_reason' => $reason, 'failed_at' => now()]);
    }

    private function safeFailure(Throwable $exception): string
    {
        return str($exception->getMessage())->replaceMatches('/https?:\/\/[^\s]+/', '[GOWA endpoint]')->limit(1000)->toString();
    }
}
