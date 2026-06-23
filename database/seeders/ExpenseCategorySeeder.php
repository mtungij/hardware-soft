<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Rent', 'Salary', 'Transport', 'Electricity', 'Water', 'Internet', 'Security', 'Maintenance', 'Other'] as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name], [
                'description' => "{$name} operating expense",
                'status' => 'active',
            ]);
        }
    }
}
