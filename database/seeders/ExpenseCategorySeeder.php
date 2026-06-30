<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::query()->value('id');

        foreach (['Rent', 'Salary', 'Transport', 'Electricity', 'Water', 'Internet', 'Security', 'Maintenance', 'Other'] as $name) {
            ExpenseCategory::query()->firstOrCreate(['company_id' => $companyId, 'name' => $name], [
                'company_id' => $companyId,
                'description' => "{$name} operating expense",
                'status' => 'active',
            ]);
        }
    }
}
