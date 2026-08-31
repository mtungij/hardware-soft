<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPortalSecurityEvent;
use App\Models\User;
use App\Support\WhatsAppPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPortalCredentialService
{
    public function __construct(private readonly WhatsAppNotificationService $whatsApp) {}

    /** @return array{customer: Customer, account: ?CustomerAccount} */
    public function createCustomer(array $data, User $actor, string $operationKey): array
    {
        abort_unless($actor->can('customers.manage_portal_access'), 403);
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $this->assertPhoneAvailable($phone);

        $result = DB::transaction(function () use ($data, $actor, $operationKey, $phone): array {
            $customer = Customer::withoutGlobalScopes()->create([
                ...$data,
                'company_id' => $actor->company_id,
                'phone' => $phone,
                'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            ]);

            $account = null;
            $password = null;
            if (($data['status'] ?? 'active') === 'active') {
                [$account, $password] = $this->createOrRotateAccount($customer, $actor, $operationKey, 'portal_onboarding');
            }

            return compact('customer', 'account', 'password');
        });

        if ($result['account'] && $result['password']) {
            $this->queueCredentialsSafely($result['customer'], $result['account'], $result['password'], 'portal_onboarding');
        }

        return ['customer' => $result['customer'], 'account' => $result['account']];
    }

    public function enableOrReset(Customer $customer, User $actor, string $operationKey, bool $reset = false): CustomerAccount
    {
        abort_unless($actor->can('customers.manage_portal_access'), 403);
        abort_unless((int) $actor->company_id === (int) $customer->company_id, 403);

        $result = DB::transaction(function () use ($customer, $actor, $operationKey, $reset): array {
            $customer = Customer::withoutGlobalScopes()->lockForUpdate()->findOrFail($customer->id);
            $existing = CustomerAccount::withoutGlobalScopes()
                ->where('customer_id', $customer->id)
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if ($existing?->last_credential_operation_key === $operationKey) {
                return ['account' => $existing, 'password' => null, 'event' => null];
            }

            $event = $reset && $existing ? 'portal_password_reset' : 'portal_access_enabled';
            [$account, $password] = $this->createOrRotateAccount($customer, $actor, $operationKey, $event, $existing);

            return compact('account', 'password', 'event');
        });

        if ($result['password'] && $result['event']) {
            $this->queueCredentialsSafely($result['account']->customer, $result['account'], $result['password'], $result['event']);
        }

        return $result['account'];
    }

    public function updateLoginPhone(Customer $customer, string $phone, User|CustomerAccount $actor): string
    {
        if ($actor instanceof User) {
            abort_unless((int) $actor->company_id === (int) $customer->company_id, 403);
        } else {
            abort_unless((int) $actor->customer_id === (int) $customer->id && (int) $actor->company_id === (int) $customer->company_id, 403);
        }

        $normalized = $this->normalizePhone($phone);
        $account = CustomerAccount::withoutGlobalScopes()->where('customer_id', $customer->id)->oldest('id')->first();
        $this->assertPhoneAvailable($normalized, $account?->id);

        $changed = DB::transaction(function () use ($customer, $account, $normalized, $actor): bool {
            $oldPhone = $customer->phone;
            Customer::withoutGlobalScopes()->whereKey($customer->id)->update(['phone' => $normalized]);

            if ($account) {
                $account->update(['phone' => $normalized, 'login_phone' => $normalized]);
            }

            if ($oldPhone !== $normalized) {
                $this->audit($customer, $account, 'portal_login_phone_changed', $actor, [
                    'old_phone' => $oldPhone,
                    'new_phone' => $normalized,
                ]);

                return true;
            }

            return false;
        });

        if ($changed) {
            $this->queuePhoneChangeSafely($customer, $account, $normalized);
        }

        return $normalized;
    }

    public function changePassword(CustomerAccount $account, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        DB::transaction(function () use ($account, $newPassword): void {
            $account->forceFill([
                'password' => $newPassword,
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
            $account->tokens()->delete();
            $this->audit($account->customer, $account, 'customer_password_changed', $account);
        });
    }

    public function requestRecovery(string $phone, string $operationKey): void
    {
        try {
            $normalized = WhatsAppPhone::normalize($phone);
        } catch (\Throwable) {
            return;
        }

        $account = CustomerAccount::withoutGlobalScopes()->where('login_phone', $normalized)->first();
        if (! $account || ! $account->isActive()) {
            return;
        }

        $result = DB::transaction(function () use ($account, $operationKey): ?array {
            $locked = CustomerAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            if ($locked->last_credential_operation_key === $operationKey) {
                return null;
            }

            [$updated, $password] = $this->createOrRotateAccount(
                $locked->customer,
                null,
                $operationKey,
                'portal_password_recovery',
                $locked,
            );

            return compact('updated', 'password');
        });

        if ($result) {
            $this->queueCredentialsSafely($result['updated']->customer, $result['updated'], $result['password'], 'portal_password_recovery');
        }
    }

    private function createOrRotateAccount(
        Customer $customer,
        ?User $actor,
        string $operationKey,
        string $event,
        ?CustomerAccount $account = null,
    ): array {
        $phone = $this->normalizePhone($customer->phone);
        $this->assertPhoneAvailable($phone, $account?->id);
        $password = $this->temporaryPassword();
        $version = ($account?->credential_version ?? 0) + 1;

        if ($account) {
            $account->forceFill([
                'name' => $customer->name,
                'phone' => $phone,
                'login_phone' => $phone,
                'email' => $customer->email,
                'password' => $password,
                'status' => 'active',
                'must_change_password' => true,
                'credential_version' => $version,
                'last_credential_operation_key' => $operationKey,
                'last_credentials_notification_id' => null,
                'last_credentials_sent_at' => null,
                'approved_at' => $account->approved_at ?: now(),
                'approved_by' => $actor?->id ?: $account->approved_by,
                'remember_token' => Str::random(60),
            ])->save();
            $account->tokens()->delete();
        } else {
            $account = CustomerAccount::withoutGlobalScopes()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone' => $phone,
                'login_phone' => $phone,
                'email' => $customer->email,
                'password' => $password,
                'status' => 'active',
                'preferred_locale' => 'sw',
                'must_change_password' => true,
                'credential_version' => $version,
                'last_credential_operation_key' => $operationKey,
                'approved_at' => now(),
                'approved_by' => $actor?->id,
            ]);
        }

        $this->audit($customer, $account, $event, $actor, ['credential_version' => $version]);
        $this->audit($customer, $account, 'portal_credentials_generated', $actor, ['credential_version' => $version]);

        return [$account, $password];
    }

    private function queueCredentialsSafely(Customer $customer, CustomerAccount $account, string $password, string $event): void
    {
        try {
            $company = Company::query()->findOrFail($customer->company_id);
            $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id],
                ['enabled' => false, 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES],
            );
            $notification = $this->whatsApp->queuePhone(
                company: $company,
                setting: $setting,
                phone: $account->login_phone,
                category: 'customer_portal',
                notificationType: $event,
                eventKey: "customer:{$customer->id}:{$event}:{$account->credential_version}",
                message: $this->credentialMessage($company, $customer, $account, $password),
                branchId: $customer->branch_id,
                metadata: ['customer_id' => $customer->id, 'customer_account_id' => $account->id, 'credential_version' => $account->credential_version],
                sensitive: true,
            );

            $account->forceFill([
                'last_credentials_notification_id' => $notification->id,
                'last_credentials_sent_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function queuePhoneChangeSafely(Customer $customer, ?CustomerAccount $account, string $phone): void
    {
        if (! $account) {
            return;
        }

        try {
            $company = Company::query()->findOrFail($customer->company_id);
            $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id], ['enabled' => false]);
            $this->whatsApp->queuePhone(
                $company,
                $setting,
                $phone,
                'customer_portal',
                'portal_login_phone_changed',
                "customer:{$customer->id}:portal-phone-changed:".hash('sha256', $phone),
                $this->phoneChangedMessage($company, $customer, $phone),
                $customer->branch_id,
                ['customer_id' => $customer->id, 'customer_account_id' => $account->id],
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function credentialMessage(Company $company, Customer $customer, CustomerAccount $account, string $password): string
    {
        $portalUrl = rtrim((string) config('app.customer_portal_url', config('app.url')), '/').'/customer/login';
        $localization = app(WhatsAppLocalization::class);
        $features = collect($localization->get($company, 'portal.features'))->map(fn (string $feature): string => '• '.$feature)->implode("\n");

        return '*'.$localization->get($company, 'portal.title')."*\n\n"
            .$localization->get($company, 'portal.hello', ['name' => $customer->name])."\n\n"
            .$localization->get($company, 'portal.registered', ['company' => $company->company_name])."\n\n"
            .$localization->get($company, 'portal.login_intro')."\n\n"
            .$localization->get($company, 'portal.phone').":\n{$account->login_phone}\n\n"
            .$localization->get($company, 'portal.temporary_password').":\n{$password}\n\nPortal:\n{$portalUrl}\n\n"
            .$localization->get($company, 'portal.security')."\n\n"
            .$localization->get($company, 'portal.features_intro')."\n\n{$features}\n\n"
            .$localization->get($company, 'portal.thanks', ['company' => $company->company_name])."\n\nHARDEX POS";
    }

    private function phoneChangedMessage(Company $company, Customer $customer, string $phone): string
    {
        $localization = app(WhatsAppLocalization::class);

        return '*'.$localization->get($company, 'portal.title')."*\n\n"
            .$localization->get($company, 'portal.hello', ['name' => $customer->name])."\n\n"
            .$localization->get($company, 'portal.phone_changed', ['phone' => $phone])."\n\nHARDEX POS";
    }

    private function temporaryPassword(): string
    {
        return 'Hdx@'.Str::random(12);
    }

    public function normalizePhone(?string $phone): string
    {
        try {
            return WhatsAppPhone::normalize($phone);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }
    }

    private function assertPhoneAvailable(string $phone, ?int $exceptAccountId = null): void
    {
        $query = CustomerAccount::withoutGlobalScopes()
            ->where('login_phone', $phone)
            ->when($exceptAccountId, fn ($query) => $query->whereKeyNot($exceptAccountId));
        $conflict = $query->exists();

        if (! $conflict) {
            $conflict = CustomerAccount::withoutGlobalScopes()
                ->when($exceptAccountId, fn ($accounts) => $accounts->whereKeyNot($exceptAccountId))
                ->whereNotNull('phone')
                ->cursor()
                ->contains(function (CustomerAccount $account) use ($phone): bool {
                    try {
                        return WhatsAppPhone::normalize($account->phone) === $phone;
                    } catch (\Throwable) {
                        return false;
                    }
                });
        }

        if ($conflict) {
            throw ValidationException::withMessages(['phone' => 'This phone number already has a customer portal account.']);
        }
    }

    private function audit(Customer $customer, ?CustomerAccount $account, string $event, User|CustomerAccount|null $actor, array $metadata = []): void
    {
        CustomerPortalSecurityEvent::withoutGlobalScopes()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'customer_account_id' => $account?->id,
            'event' => $event,
            'actor_type' => $actor instanceof User ? 'staff' : ($actor instanceof CustomerAccount ? 'customer' : 'system'),
            'actor_id' => $actor?->id,
            'metadata' => $metadata,
        ]);
    }
}
