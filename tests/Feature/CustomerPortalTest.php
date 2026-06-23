<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function portalCustomerAccount(string $status = 'active'): CustomerAccount
{
    $customer = Customer::create([
        'branch_id' => Branch::query()->value('id'),
        'name' => 'Portal Customer',
        'phone' => '+255700000001',
        'email' => fake()->unique()->safeEmail(),
        'customer_type' => 'credit',
        'credit_limit' => 100000,
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);

    return CustomerAccount::create([
        'customer_id' => $customer->id,
        'name' => 'Portal Customer',
        'phone' => '+255700000001',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
        'status' => $status,
    ]);
}

test('customer portal guest and auth pages render', function () {
    $this->get('/customer/login')->assertOk()->assertSee('Customer Login');
    $this->get('/customer/register')->assertOk()->assertSee('Create Customer Account');
    $this->get('/customer/dashboard')->assertRedirect('/customer/login');
});

test('active customer can access portal pages', function () {
    $account = portalCustomerAccount();

    $this->actingAs($account, 'customer')->get('/customer/dashboard')->assertOk()->assertSee('Customer Dashboard');
    $this->actingAs($account, 'customer')->get('/customer/debts')->assertOk()->assertSee('My Debts');
    $this->actingAs($account, 'customer')->get('/customer/receipts')->assertOk()->assertSee('My Receipts');
    $this->actingAs($account, 'customer')->get('/customer/deposits')->assertOk()->assertSee('My Deposits');
    $this->actingAs($account, 'customer')->get('/customer/statement')->assertOk()->assertSee('Customer Statement');
});

test('pending customer is limited to pending page', function () {
    $account = portalCustomerAccount('pending');

    $this->actingAs($account, 'customer')->get('/customer/pending')->assertOk()->assertSee('Account Pending Approval');
    $this->actingAs($account, 'customer')->get('/customer/dashboard')->assertRedirect('/customer/pending');
});

test('admin can open customer portal review queues', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)->get('/admin/customer-accounts')->assertOk()->assertSee('Customer Accounts');
    $this->actingAs($admin)->get('/admin/customer-receipts')->assertOk()->assertSee('Customer Receipts');
    $this->actingAs($admin)->get('/admin/customer-deposits')->assertOk()->assertSee('Customer Deposits');
});
