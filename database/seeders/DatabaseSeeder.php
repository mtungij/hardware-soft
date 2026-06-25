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
            StockLocationSeeder::class,
            CategorySeeder::class,
            UnitSeeder::class,
           
        ]);
    }
}
