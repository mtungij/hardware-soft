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
            ['½', '½', 'Half inch'],
            ['¾ ³ᵐᵐ', '¾ ³ᵐᵐ', 'Three quarter inch, 3mm thickness'],
            ['¾ ⁴ᵐᵐ', '¾ ⁴ᵐᵐ', 'Three quarter inch, 4mm thickness'],
            ['1', '1', 'One inch'],
            ['1 ³ᵐᵐ', '1 ³ᵐᵐ', 'One inch, 3mm thickness'],
            ['1 ⁴ᵐᵐ', '1 ⁴ᵐᵐ', 'One inch, 4mm thickness'],
            ['1 ⁶ᵐᵐ', '1 ⁶ᵐᵐ', 'One inch, 6mm thickness'],
            ['1¼', '1¼', 'One and quarter inch'],
            ['1½', '1½', 'One and half inch'],
            ['1.5mm', '1.5mm', '1.5mm thickness'],
            ['1½ ³ᵐᵐ', '1½ ³ᵐᵐ', 'One and half inch, 3mm thickness'],
            ['1½ ⁴ᵐᵐ', '1½ ⁴ᵐᵐ', 'One and half inch, 4mm thickness'],
            ['1½ ⁶ᵐᵐ', '1½ ⁶ᵐᵐ', 'One and half inch, 6mm thickness'],
            ['2', '2', 'Two inch'],
            ['2½', '2½', 'Two and half inch'],
            ['3', '3', 'Three inch'],
            ['4', '4', 'Four inch'],
            ['6', '6', 'Six inch'],
            ['½ × ½', '½ × ½', 'Half inch by half inch'],
            ['1 × 1', '1 × 1', 'One inch by one inch'],
            ['1¼ × 1¼', '1¼ × 1¼', 'One and quarter inch by one and quarter inch'],
            ['1½ × 1', '1½ × 1', 'One and half inch by one inch'],
            ['1½ × 1½', '1½ × 1½', 'One and half inch by one and half inch'],
            ['2 × 2', '2 × 2', 'Two inch by two inch'],
            ['2 × 3', '2 × 3', 'Two inch by three inch'],
            ['2 × 4 (2mm)', '2 × 4 (2mm)', 'Two inch by four inch, 2mm thickness'],
            ['2 × 4 (3mm)', '2 × 4 (3mm)', 'Two inch by four inch, 3mm thickness'],
            ['1½ × 2½', '1½ × 2½', 'One and half inch by two and half inch'],
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
