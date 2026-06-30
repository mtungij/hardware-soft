<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        Setting::query()->firstOrCreate(
            ['company_id' => $branch?->company_id, 'company_name' => 'Hardex POS'],
            [
                'company_id' => $branch?->company_id,
                'company_phone' => '+255 700 000 000',
                'company_email' => 'info@buildmart.test',
                'company_address' => 'Hardex Head Office, Dar es Salaam',
                'currency' => 'TZS',
                'receipt_footer_text' => 'Thank you for shopping with Hardex POS.',
                'tax_enabled' => true,
                'default_branch_id' => $branch?->id,
                'theme_color' => '#f97316',
                'mail_host' => '127.0.0.1',
                'mail_port' => 2525,
                'mail_encryption' => null,
                'mail_from_email' => 'purchases@buildmart.test',
                'mail_from_name' => 'Hardware Software',
            ]
        );
    }
}
