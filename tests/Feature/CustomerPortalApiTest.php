<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function apiCustomerAccount(string $status = 'active'): CustomerAccount
{
    $email = fake()->unique()->safeEmail();
    $branch = Branch::query()->firstOrFail();

    $customer = Customer::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'API Customer',
        'phone' => '+255700000002',
        'email' => $email,
        'customer_type' => 'credit',
        'credit_limit' => 100000,
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);

    return CustomerAccount::create([
        'company_id' => $branch->company_id,
        'customer_id' => $customer->id,
        'name' => 'API Customer',
        'phone' => '+255700000002',
        'login_phone' => '255700000002',
        'email' => $email,
        'password' => 'password',
        'status' => $status,
    ]);
}

test('customer api login returns sanctum token and dashboard data', function () {
    $account = apiCustomerAccount();

    $login = $this->postJson('/api/customer/login', [
        'login' => '0700000002',
        'password' => 'password',
        'device_name' => 'Feature Test',
    ])->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('account.status', 'active');

    $token = $login->json('token');

    $this->withToken($token)
        ->getJson('/api/customer/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'total_outstanding_debt',
            'available_deposit_balance',
            'credit_limit',
            'available_credit',
            'last_payment',
            'last_purchase',
            'pending_receipts',
            'pending_deposits',
        ]);
});

test('pending customer api account is blocked from protected endpoints', function () {
    $account = apiCustomerAccount('pending');
    $token = $account->createToken('Feature Test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/customer/dashboard')
        ->assertStatus(423)
        ->assertJsonPath('status', 'pending');
});
