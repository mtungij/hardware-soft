<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RolePermissionSeeder::class,
            SettingSeeder::class,
            SuperAdminSeeder::class,
            CategorySeeder::class,
            UnitSeeder::class,
            SupplierSeeder::class,
            CustomerSeeder::class,
            ProductSeeder::class,
            StockLocationSeeder::class,
            PurchaseSeeder::class,
            StockTransferSeeder::class,
            SaleSeeder::class,
            ExpenseCategorySeeder::class,
            AccountingSeeder::class,
        ]);
    }
}
