<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Services\CustomerPortalCredentialService;
use App\Support\WhatsAppPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $phone = WhatsAppPhone::normalize($data['login']);
        } catch (\Throwable) {
            $phone = 'invalid';
        }
        $account = CustomerAccount::withoutGlobalScopes()->where('login_phone', $phone)->first();

        if (! $account || ! Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages(['login' => 'Invalid customer credentials.']);
        }

        if ($account->isSuspended()) {
            return response()->json(['message' => 'Customer account is suspended.', 'status' => 'suspended'], 403);
        }

        $account->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $account->createToken($data['device_name'] ?? 'customer-portal')->plainTextToken,
            'token_type' => 'Bearer',
            'account' => $this->accountPayload($account),
            'password_change_required' => (bool) $account->must_change_password,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customer_accounts,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $phone = app(CustomerPortalCredentialService::class)->normalizePhone($data['phone']);
        $customer = Customer::withoutGlobalScopes()->whereIn('phone', [$phone, '+'.$phone, '0'.substr($phone, 3)])->first();

        if ($customer?->portalAccounts()->exists()) {
            throw ValidationException::withMessages(['phone' => 'A customer portal account already exists for this customer.']);
        }

        $account = DB::transaction(function () use ($data, $phone, $customer) {
            if (! $customer) {
                $company = Company::current();
                abort_unless($company, 503);
                $customer = Customer::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'branch_id' => Branch::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->value('id') ?? Branch::withoutGlobalScopes()->where('company_id', $company->id)->value('id'),
                    'name' => $data['business_name'] ?: $data['name'],
                    'phone' => $phone,
                    'email' => ($data['email'] ?? null) ?: null,
                    'address' => $data['branch_name'],
                    'customer_type' => 'credit',
                    'credit_limit' => 0,
                    'opening_balance' => 0,
                    'balance_amount' => 0,
                    'status' => 'active',
                ]);
            }

            return CustomerAccount::withoutGlobalScopes()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'phone' => $phone,
                'login_phone' => $phone,
                'email' => ($data['email'] ?? null) ?: null,
                'password' => $data['password'],
                'status' => 'pending',
                'preferred_locale' => 'sw',
                'must_change_password' => false,
            ]);
        });

        return response()->json([
            'token' => $account->createToken($data['device_name'] ?? 'customer-portal')->plainTextToken,
            'token_type' => 'Bearer',
            'account' => $this->accountPayload($account),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function changePassword(Request $request, CustomerPortalCredentialService $credentials): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $credentials->changePassword($request->user(), $data['current_password'], $data['password']);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function forgotPassword(Request $request, CustomerPortalCredentialService $credentials): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $credentials->requestRecovery($data['phone'], (string) Str::uuid());

        return response()->json(['message' => 'If the phone is registered and eligible, recovery credentials have been queued.']);
    }

    private function accountPayload(CustomerAccount $account): array
    {
        return [
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'name' => $account->name,
            'phone' => $account->phone,
            'email' => $account->email,
            'status' => $account->status,
            'preferred_locale' => $account->preferred_locale ?: 'sw',
            'must_change_password' => (bool) $account->must_change_password,
            'otp_ready' => true,
            'google_login_ready' => true,
        ];
    }
}
