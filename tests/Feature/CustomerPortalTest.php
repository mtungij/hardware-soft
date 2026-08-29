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
    $branch = Branch::query()->firstOrFail();
    $customer = Customer::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
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
        'company_id' => $branch->company_id,
        'customer_id' => $customer->id,
        'name' => 'Portal Customer',
        'phone' => '+255700000001',
        'login_phone' => '255700000001',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
        'status' => $status,
    ]);
}

test('customer portal guest and auth pages render', function () {
    $this->get('/customer/login')->assertOk()->assertSeeVolt('customer.auth.login')->assertSee('0629 364 847');
    $this->get('/customer/register')->assertOk()->assertSeeVolt('customer.auth.register');
    $this->get('/customer/dashboard')->assertRedirect('/customer/login');
});

test('active customer can access portal pages', function () {
    $account = portalCustomerAccount();

    $this->actingAs($account, 'customer')->get('/customer/dashboard')->assertOk()->assertSeeVolt('customer.dashboard');
    $this->actingAs($account, 'customer')->get('/customer/debts')->assertOk()->assertSeeVolt('customer.debts.index');
    $this->actingAs($account, 'customer')->get('/customer/receipts')->assertOk()->assertSeeVolt('customer.receipts.index');
    $this->actingAs($account, 'customer')->get('/customer/deposits')->assertOk()->assertSeeVolt('customer.deposits.index');
    $this->actingAs($account, 'customer')->get('/customer/statement')->assertOk()->assertSeeVolt('customer.statement');
});

test('pending customer is limited to pending page', function () {
    $account = portalCustomerAccount('pending');

    $this->actingAs($account, 'customer')->get('/customer/pending')->assertOk()->assertSeeVolt('customer.pending');
    $this->actingAs($account, 'customer')->get('/customer/dashboard')->assertRedirect('/customer/pending');
});

test('admin can open customer portal review queues', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)->get('/admin/customer-accounts')->assertOk()->assertSeeVolt('admin.customer-accounts.index');
    $this->actingAs($admin)->get('/admin/customer-receipts')->assertOk()->assertSeeVolt('admin.customer-receipts.index');
    $this->actingAs($admin)->get('/admin/customer-deposits')->assertOk()->assertSeeVolt('admin.customer-deposits.index');
});
