<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        $products = [
            ['Huaxin Cement 32.5N 50kg', 'HDX-CEM-HUX-325N', '899100100101', 'CEM', 'bag', 'Huaxin Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Huaxin Cement 32.5R 50kg', 'HDX-CEM-HUX-325R', '899100100102', 'CEM', 'bag', 'Huaxin Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Huaxin Cement 42.5N 50kg', 'HDX-CEM-HUX-425N', '899100100103', 'CEM', 'bag', 'Huaxin Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Huaxin Cement 42.5R 50kg', 'HDX-CEM-HUX-425R', '899100100104', 'CEM', 'bag', 'Huaxin Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Huaxin Cement 52.5N 50kg', 'HDX-CEM-HUX-525N', '899100100105', 'CEM', 'bag', 'Huaxin Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Nyati Cement 32.5N 50kg', 'HDX-CEM-NYA-325N', '899100100106', 'CEM', 'bag', 'Nyati Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Nyati Cement 32.5R 50kg', 'HDX-CEM-NYA-325R', '899100100107', 'CEM', 'bag', 'Nyati Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Nyati Cement 42.5N 50kg', 'HDX-CEM-NYA-425N', '899100100108', 'CEM', 'bag', 'Nyati Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Nyati Cement 42.5R 50kg', 'HDX-CEM-NYA-425R', '899100100109', 'CEM', 'bag', 'Nyati Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Nyati Cement 52.5N 50kg', 'HDX-CEM-NYA-525N', '899100100110', 'CEM', 'bag', 'Nyati Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Twiga Cement 32.5N 50kg', 'HDX-CEM-TWI-325N', '899100100111', 'CEM', 'bag', 'Twiga Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Twiga Cement 32.5R 50kg', 'HDX-CEM-TWI-325R', '899100100112', 'CEM', 'bag', 'Twiga Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Twiga Cement 42.5N 50kg', 'HDX-CEM-TWI-425N', '899100100113', 'CEM', 'bag', 'Twiga Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Twiga Cement 42.5R 50kg', 'HDX-CEM-TWI-425R', '899100100114', 'CEM', 'bag', 'Twiga Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Twiga Cement 52.5N 50kg', 'HDX-CEM-TWI-525N', '899100100115', 'CEM', 'bag', 'Twiga Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Tembo Cement 32.5N 50kg', 'HDX-CEM-TEM-325N', '899100100116', 'CEM', 'bag', 'Tembo Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Tembo Cement 32.5R 50kg', 'HDX-CEM-TEM-325R', '899100100117', 'CEM', 'bag', 'Tembo Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Tembo Cement 42.5N 50kg', 'HDX-CEM-TEM-425N', '899100100118', 'CEM', 'bag', 'Tembo Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Tembo Cement 42.5R 50kg', 'HDX-CEM-TEM-425R', '899100100119', 'CEM', 'bag', 'Tembo Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Tembo Cement 52.5N 50kg', 'HDX-CEM-TEM-525N', '899100100120', 'CEM', 'bag', 'Tembo Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Tanga Cement 32.5N 50kg', 'HDX-CEM-TAN-325N', '899100100121', 'CEM', 'bag', 'Tanga Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Tanga Cement 32.5R 50kg', 'HDX-CEM-TAN-325R', '899100100122', 'CEM', 'bag', 'Tanga Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Tanga Cement 42.5N 50kg', 'HDX-CEM-TAN-425N', '899100100123', 'CEM', 'bag', 'Tanga Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Tanga Cement 42.5R 50kg', 'HDX-CEM-TAN-425R', '899100100124', 'CEM', 'bag', 'Tanga Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Tanga Cement 52.5N 50kg', 'HDX-CEM-TAN-525N', '899100100125', 'CEM', 'bag', 'Tanga Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Lulu Cement 32.5N 50kg', 'HDX-CEM-LUL-325N', '899100100126', 'CEM', 'bag', 'Lulu Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Lulu Cement 32.5R 50kg', 'HDX-CEM-LUL-325R', '899100100127', 'CEM', 'bag', 'Lulu Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Lulu Cement 42.5N 50kg', 'HDX-CEM-LUL-425N', '899100100128', 'CEM', 'bag', 'Lulu Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Lulu Cement 42.5R 50kg', 'HDX-CEM-LUL-425R', '899100100129', 'CEM', 'bag', 'Lulu Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Lulu Cement 52.5N 50kg', 'HDX-CEM-LUL-525N', '899100100130', 'CEM', 'bag', 'Lulu Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Camel Cement 32.5N 50kg', 'HDX-CEM-CAM-325N', '899100100131', 'CEM', 'bag', 'Camel Cement', '32.5N 50kg', 15000, 18000, 17000, 200, true],
            ['Camel Cement 32.5R 50kg', 'HDX-CEM-CAM-325R', '899100100132', 'CEM', 'bag', 'Camel Cement', '32.5R 50kg', 15200, 18200, 17200, 200, true],
            ['Camel Cement 42.5N 50kg', 'HDX-CEM-CAM-425N', '899100100133', 'CEM', 'bag', 'Camel Cement', '42.5N 50kg', 15800, 18800, 17800, 200, true],
            ['Camel Cement 42.5R 50kg', 'HDX-CEM-CAM-425R', '899100100134', 'CEM', 'bag', 'Camel Cement', '42.5R 50kg', 16000, 19000, 18000, 200, true],
            ['Camel Cement 52.5N 50kg', 'HDX-CEM-CAM-525N', '899100100135', 'CEM', 'bag', 'Camel Cement', '52.5N 50kg', 17000, 20000, 19000, 200, true],

            ['Tofali Inch 4', 'HDX-TOF-IN4', '899100100201', 'TOF', 'pcs', 'Local', 'Inch 4', 500, 700, 650, 1000, false],
            ['Tofali Inch 5', 'HDX-TOF-IN5', '899100100202', 'TOF', 'pcs', 'Local', 'Inch 5', 600, 800, 750, 1000, false],
            ['Tofali Inch 6', 'HDX-TOF-IN6', '899100100203', 'TOF', 'pcs', 'Local', 'Inch 6', 700, 900, 850, 1000, false],
            ['Tofali Inch 8', 'HDX-TOF-IN8', '899100100204', 'TOF', 'pcs', 'Local', 'Inch 8', 900, 1200, 1100, 1000, false],

            ['Nondo 8mm', 'HDX-NON-008', '899100100301', 'NON', 'pcs', 'SteelPro', '8mm', 12000, 14500, 13800, 300, true],
            ['Nondo 10mm', 'HDX-NON-010', '899100100302', 'NON', 'pcs', 'SteelPro', '10mm', 18000, 22000, 21000, 250, true],
            ['Nondo 12mm', 'HDX-NON-012', '899100100303', 'NON', 'pcs', 'SteelPro', '12mm', 25000, 30000, 28500, 200, true],
            ['Nondo 16mm', 'HDX-NON-016', '899100100304', 'NON', 'pcs', 'SteelPro', '16mm', 45000, 55000, 52000, 150, true],
            ['Nondo 20mm', 'HDX-NON-020', '899100100305', 'NON', 'pcs', 'SteelPro', '20mm', 70000, 85000, 81000, 100, true],
            ['Nondo 25mm', 'HDX-NON-025', '899100100306', 'NON', 'pcs', 'SteelPro', '25mm', 105000, 125000, 120000, 80, true],
            ['Nondo 32mm', 'HDX-NON-032', '899100100307', 'NON', 'pcs', 'SteelPro', '32mm', 170000, 200000, 190000, 50, true],

            ['Mabati Gauge 28', 'HDX-MAB-G28', '899100100002', 'MAB', 'pcs', 'ALAF', 'Gauge 28', 17500, 22000, 20500, 80, true],

            ['White Paint 20L', 'HDX-RAN-W20', '899100100004', 'RAN', 'ltr', 'Prime Paints', '20L', 52000, 68000, 63500, 25, true],

            ['Wire Mesh A142', 'HDX-WMS-A142', '899100100401', 'WMS', 'pcs', 'SteelPro', 'A142', 85000, 105000, 98000, 30, true],
            ['Wire Mesh A193', 'HDX-WMS-A193', '899100100402', 'WMS', 'pcs', 'SteelPro', 'A193', 105000, 125000, 118000, 30, true],
            ['Wire Mesh A252', 'HDX-WMS-A252', '899100100403', 'WMS', 'pcs', 'SteelPro', 'A252', 125000, 145000, 138000, 20, true],

            ['Fence Wire 12 Gauge', 'HDX-FEN-12G', '899100100404', 'FEN', 'roll', 'Kifaru', '12 Gauge', 95000, 120000, 115000, 25, true],
            ['Fence Wire 14 Gauge', 'HDX-FEN-14G', '899100100405', 'FEN', 'roll', 'Kifaru', '14 Gauge', 85000, 105000, 98000, 25, true],
            ['Razor Wire', 'HDX-FEN-RAZ', '899100100406', 'FEN', 'roll', 'Kifaru', 'Razor', 180000, 220000, 210000, 15, true],

            ['Marine Board 18mm', 'HDX-MRB-18', '899100100407', 'MRB', 'pcs', 'Marine Ply', '18mm', 65000, 85000, 80000, 50, true],
            ['Marine Board 25mm', 'HDX-MRB-25', '899100100408', 'MRB', 'pcs', 'Marine Ply', '25mm', 95000, 120000, 115000, 30, true],

            ['Floor Tile 60x60', 'HDX-TIL-6060', '899100100409', 'TIL', 'box', 'Twyford', '60x60', 28000, 35000, 33000, 100, true],
            ['Wall Tile 30x60', 'HDX-TIL-3060', '899100100410', 'TIL', 'box', 'Twyford', '30x60', 22000, 30000, 28000, 100, true],
            ['Porcelain Tile 80x80', 'HDX-TIL-8080', '899100100411', 'TIL', 'box', 'Twyford', '80x80', 45000, 58000, 55000, 50, true],

            ['PVC Pipe 1/2 Inch', 'HDX-PVC-05', '899100100412', 'PIP', 'pcs', 'Plasco', '1/2 Inch', 3500, 5000, 4500, 150, true],
            ['PVC Pipe 1 Inch', 'HDX-PVC-10', '899100100413', 'PIP', 'pcs', 'Plasco', '1 Inch', 7000, 9500, 9000, 120, true],
            ['PVC Pipe 2 Inch', 'HDX-PVC-20', '899100100414', 'PIP', 'pcs', 'Plasco', '2 Inch', 18000, 24000, 23000, 80, true],
            ['PVC Pipe 4 Inch', 'HDX-PVC-40', '899100100415', 'PIP', 'pcs', 'Plasco', '4 Inch', 45000, 60000, 58000, 50, true],

            ['Toilet Sink Standard', 'HDX-TSK-001', '899100100416', 'TSK', 'pcs', 'Twyford', 'Standard', 35000, 50000, 47000, 30, true],
            ['Toilet Sink Premium', 'HDX-TSK-002', '899100100417', 'TSK', 'pcs', 'Twyford', 'Premium', 65000, 85000, 80000, 20, true],

            ['Kitchen Sink Single Bowl', 'HDX-KSK-001', '899100100418', 'KSK', 'pcs', 'StainlessPro', 'Single Bowl', 55000, 75000, 70000, 20, true],
            ['Kitchen Sink Double Bowl', 'HDX-KSK-002', '899100100419', 'KSK', 'pcs', 'StainlessPro', 'Double Bowl', 85000, 110000, 105000, 15, true],

            ['Water Tank 500L', 'HDX-WTK-500', '899100100420', 'WTK', 'pcs', 'SimTank', '500 Litres', 140000, 180000, 170000, 15, true],
            ['Water Tank 1000L', 'HDX-WTK-1000', '899100100421', 'WTK', 'pcs', 'SimTank', '1000 Litres', 250000, 320000, 300000, 10, true],
            ['Water Tank 2000L', 'HDX-WTK-2000', '899100100422', 'WTK', 'pcs', 'SimTank', '2000 Litres', 450000, 560000, 530000, 5, true],
            ['Water Tank 5000L', 'HDX-WTK-5000', '899100100423', 'WTK', 'pcs', 'SimTank', '5000 Litres', 980000, 1200000, 1150000, 3, true],

            ['Jembe No.2', 'HDX-HOE-002', '899100100424', 'HOE', 'pcs', 'KilimoPro', 'No.2', 8500, 12000, 11000, 80, true],
            ['Jembe No.3', 'HDX-HOE-003', '899100100425', 'HOE', 'pcs', 'KilimoPro', 'No.3', 9000, 13000, 12000, 80, true],

            ['Gypsum Board 9mm', 'HDX-GYP-009', '899100100426', 'GYP', 'pcs', 'Knauf', '9mm', 22000, 30000, 28000, 60, true],
            ['Gypsum Board 12mm', 'HDX-GYP-012', '899100100427', 'GYP', 'pcs', 'Knauf', '12mm', 28000, 36000, 34000, 60, true],
            ['Moisture Resistant Gypsum Board', 'HDX-GYP-MR', '899100100428', 'GYP', 'pcs', 'Knauf', 'MR Board', 35000, 45000, 42000, 40, true],
        ];

        foreach ($products as [$name, $sku, $barcode, $categoryCode, $unitShortName, $brand, $modelSize, $buyingPrice, $sellingPrice, $wholesalePrice, $reorderLevel, $taxable]) {
            $category = Category::query()->where('code', $categoryCode)->first();
            $unit = Unit::query()->where('short_name', $unitShortName)->first();

            if (! $category || ! $unit) {
                continue;
            }

            Product::query()->firstOrCreate(
                ['sku' => $sku],
                [
                    'branch_id' => $branch?->id,
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'name' => $name,
                    'barcode' => $barcode,
                    'brand' => $brand,
                    'model_size' => $modelSize,
                    'image' => null,
                    'buying_price' => $buyingPrice,
                    'selling_price' => $sellingPrice,
                    'wholesale_price' => $wholesalePrice,
                    'reorder_level' => $reorderLevel,
                    'taxable' => $taxable,
                    'status' => 'active',
                ]
            );
        }
    }
}