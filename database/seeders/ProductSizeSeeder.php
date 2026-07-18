<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ProductSize;
use Illuminate\Database\Seeder;

class ProductSizeSeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
    ) {}

    public function run(): void
    {
        $sizes = [
            ['½ × ½', '½ × ½', 'Half inch by half inch'],
            ['1 × 1', '1 × 1', 'One inch by one inch'],
            ['1¼ × 1¼', '1¼ × 1¼', 'One and quarter inch by one and quarter inch'],
            ['1½ × 1', '1½ × 1', 'One and half inch by one inch'],
            ['1½ × 1½', '1½ × 1½', 'One and half inch by one and half inch'],
            ['2 × 2', '2 × 2', 'Two inch by two inch'],
            ['2 × 3', '2 × 3', 'Two inch by three inch'],
            ['2 × 4 (2mm)', '2 × 4 (2mm)', 'Two inch by four inch, 2mm thickness'],
            ['2½ × 2½', '2½ × 2½', 'Two and half inch by two and half inch'],
            ['3 × 3', '3 × 3', 'Three inch by three inch'],
            ['4 × 4 (3mm)', '4 × 4 (3mm)', 'Four inch by four inch, 3mm thickness'],
            ['6 × 6 (3mm)', '6 × 6 (3mm)', 'Six inch by six inch, 3mm thickness'],
        ];

        foreach ($this->companies() as $company) {
            foreach ($sizes as [$name, $symbol, $description]) {
                ProductSize::query()->updateOrCreate(
                    ['company_id' => $company->id, 'symbol' => $symbol],
                    [
                        'name' => $name,
                        'description' => $description,
                        'status' => 'active',
                    ]
                );
            }
        }
    }

    /**
     * @return iterable<Company>
     */
    private function companies(): iterable
    {
        if ($this->companyId) {
            return [Company::query()->findOrFail($this->companyId)];
        }

        $seedCompanyId = env('SEED_COMPANY_ID');

        if ($seedCompanyId) {
            return [Company::query()->findOrFail((int) $seedCompanyId)];
        }

        return Company::query()->where('business_type', 'Hardware Store')->orderBy('id')->get();
    }
}
