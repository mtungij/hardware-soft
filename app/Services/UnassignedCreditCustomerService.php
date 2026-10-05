<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\StockLocation;

class UnassignedCreditCustomerService
{
    public function forLocation(StockLocation $location): Customer
    {
        $companyId = $location->company_id;

        return Customer::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => $location->branch_id,
                'is_unassigned_credit_customer' => true,
            ],
            [
                'branch_id' => $location->branch_id,
                'name' => 'Mteja wa Mkopo Ambaye Hajatajwa',
                'phone' => 'UNASSIGNED-CREDIT-'.$companyId.'-'.$location->branch_id,
                'email' => null,
                'address' => null,
                'customer_type' => 'credit',
                'credit_limit' => 0,
                'opening_balance' => 0,
                'balance_amount' => 0,
                'status' => 'active',
                'is_system_customer' => true,
            ],
        );
    }
}
