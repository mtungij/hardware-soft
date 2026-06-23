<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        $account = CustomerAccount::query()
            ->where('email', $data['login'])
            ->orWhere('phone', $data['login'])
            ->first();

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
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $customer = Customer::query()
            ->where('email', $data['email'])
            ->orWhere('phone', $data['phone'])
            ->first();

        if ($customer?->portalAccounts()->exists()) {
            throw ValidationException::withMessages(['email' => 'A customer portal account already exists for this customer.']);
        }

        if (! $customer) {
            $customer = Customer::create([
                'branch_id' => Branch::query()->where('status', 'active')->value('id') ?? Branch::query()->value('id'),
                'name' => $data['business_name'] ?: $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'address' => $data['branch_name'],
                'customer_type' => 'credit',
                'credit_limit' => 0,
                'opening_balance' => 0,
                'balance_amount' => 0,
                'status' => 'active',
            ]);
        }

        $account = CustomerAccount::create([
            'customer_id' => $customer->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 'pending',
        ]);

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

    private function accountPayload(CustomerAccount $account): array
    {
        return [
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'name' => $account->name,
            'phone' => $account->phone,
            'email' => $account->email,
            'status' => $account->status,
            'otp_ready' => true,
            'google_login_ready' => true,
        ];
    }
}
