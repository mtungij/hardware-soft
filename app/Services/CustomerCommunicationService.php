<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementCustomer;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerDeposit;
use App\Models\CustomerMessage;
use App\Models\CustomerNotification;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerCommunicationService
{
    public function targetCustomers(string $visibilityType, array $filters = []): Collection
    {
        return Customer::query()
            ->where('status', 'active')
            ->when($visibilityType === 'selected_customers', function (Builder $query) use ($filters) {
                $query->whereIn('id', array_filter($filters['customer_ids'] ?? []));
            })
            ->when($visibilityType === 'customer_group', function (Builder $query) use ($filters) {
                $group = $filters['customer_group'] ?? null;

                if ($group) {
                    $query->where('customer_type', $group);
                }
            })
            ->when($visibilityType === 'customers_with_debt', function (Builder $query) {
                $query->whereHas('sales', fn (Builder $sales) => $sales
                    ->where('status', 'completed')
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->where('balance_amount', '>', 0));
            })
            ->when($visibilityType === 'customers_with_deposits', function (Builder $query) {
                $query->whereHas('deposits', fn (Builder $deposits) => $deposits
                    ->whereIn('status', ['approved', 'partial'])
                    ->where('balance_amount', '>', 0));
            })
            ->when($visibilityType === 'selected_branches', function (Builder $query) use ($filters) {
                $query->whereIn('branch_id', array_filter($filters['branch_ids'] ?? []));
            })
            ->when($visibilityType === 'vip_customers', function (Builder $query) {
                $query->where(fn (Builder $vip) => $vip
                    ->whereIn('customer_type', ['contractor', 'wholesale'])
                    ->orWhere('credit_limit', '>=', 1000000));
            })
            ->with(['portalAccounts' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get();
    }

    public function publishAnnouncement(Announcement $announcement): int
    {
        return DB::transaction(function () use ($announcement) {
            $announcement->forceFill([
                'status' => 'published',
                'publish_date' => $announcement->publish_date ?: now(),
            ])->save();

            $customers = $this->targetCustomers(
                $announcement->visibility_type,
                $announcement->target_filters ?: []
            );

            foreach ($customers as $customer) {
                $account = $customer->portalAccounts->first();

                AnnouncementCustomer::query()->updateOrCreate(
                    [
                        'announcement_id' => $announcement->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'customer_account_id' => $account?->id,
                        'is_delivered' => true,
                        'delivered_at' => now(),
                    ]
                );

                CustomerNotification::query()->updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'notifiable_type' => Announcement::class,
                        'notifiable_id' => $announcement->id,
                    ],
                    [
                        'customer_account_id' => $account?->id,
                        'type' => 'announcement',
                        'title' => $announcement->title,
                        'message' => str($announcement->message)->limit(220)->value(),
                        'priority' => $announcement->priority,
                        'delivered_at' => now(),
                        'channels' => ['portal'],
                    ]
                );
            }

            return $customers->count();
        });
    }

    public function sendCustomerMessage(CustomerMessage $message): CustomerMessage
    {
        return DB::transaction(function () use ($message) {
            $account = $message->account ?: CustomerAccount::query()
                ->where('customer_id', $message->customer_id)
                ->where('status', 'active')
                ->first();

            $message->forceFill([
                'customer_account_id' => $account?->id,
                'status' => 'sent',
                'sent_at' => now(),
                'channels' => ['portal'],
            ])->save();

            CustomerNotification::query()->create([
                'customer_account_id' => $account?->id,
                'customer_id' => $message->customer_id,
                'type' => 'customer_message',
                'title' => $message->subject,
                'message' => str($message->message)->limit(220)->value(),
                'priority' => $message->priority,
                'notifiable_type' => CustomerMessage::class,
                'notifiable_id' => $message->id,
                'delivered_at' => now(),
                'channels' => ['portal'],
            ]);

            return $message;
        });
    }
}
