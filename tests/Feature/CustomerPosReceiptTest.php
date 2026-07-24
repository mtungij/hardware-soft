<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->actingAs($this->admin);
});

test('cash customer receipt contains only customer-facing product and payment details', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $size = ProductSize::where('symbol', '2 × 4 (2mm)')->firstOrFail();
    $sale = makeCustomerReceiptSale($this->branch, $this->admin, [
        'subtotal' => 90000,
        'total_amount' => 90000,
        'paid_amount' => 90000,
        'balance_amount' => 0,
        'change_amount' => 0,
        'payment_status' => 'paid',
    ], [
        'product_name' => 'Square Tube',
        'product_size_id' => $size->id,
        'selling_unit_id' => $piece->id,
        'base_unit_id' => $piece->id,
        'quantity' => 2,
        'base_quantity' => 2,
        'unit_price' => 45000,
        'line_total' => 90000,
        'sold_from_label' => 'Main Warehouse',
    ]);

    $component = Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('Square Tube')
        ->assertSee('2 × 4 (2mm)')
        ->assertSee('pcs')
        ->assertSee('45,000')
        ->assertSee('90,000')
        ->assertSee('Change')
        ->assertDontSee('Outstanding')
        ->assertDontSee('Balance')
        ->assertDontSee('Stock Source')
        ->assertDontSee('Stock location')
        ->assertDontSee('Sehemu ya Stock')
        ->assertDontSee('Main Warehouse')
        ->assertDontSee('Base Quantity Deducted')
        ->assertDontSee('Selling Quantity')
        ->assertDontSee('Conversion')
        ->assertDontSee('Discount');

    expect(substr_count($component->html(), 'Sale Type:'))->toBe(1);
});

test('fractional receipt prints selling quantity and selling unit without base-unit details', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $metre = Unit::where('short_name', 'm')->firstOrFail();
    $sale = makeCustomerReceiptSale($this->branch, $this->admin, [
        'subtotal' => 15000,
        'total_amount' => 15000,
        'paid_amount' => 15000,
        'balance_amount' => 0,
        'payment_status' => 'paid',
    ], [
        'product_name' => 'PVC Pipe 3m',
        'selling_unit_id' => $metre->id,
        'base_unit_id' => $piece->id,
        'conversion_factor' => 3,
        'quantity' => 1.5,
        'base_quantity' => 0.5,
        'unit_price' => 10000,
        'line_total' => 15000,
    ]);

    Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('PVC Pipe 3m')
        ->assertSee('1.5')
        ->assertSee('m')
        ->assertSee('10,000')
        ->assertSee('15,000')
        ->assertDontSee('pcs')
        ->assertDontSee('Base Quantity')
        ->assertDontSee('Unit Conversion');
});

test('discount and tax rows only appear when they have customer-visible values', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $sale = makeCustomerReceiptSale($this->branch, $this->admin, [
        'subtotal' => 20000,
        'discount_amount' => 1000,
        'tax_amount' => 500,
        'total_amount' => 19500,
        'paid_amount' => 20000,
        'balance_amount' => 0,
        'change_amount' => 500,
        'payment_status' => 'paid',
    ], [
        'selling_unit_id' => $piece->id,
        'base_unit_id' => $piece->id,
        'quantity' => 2,
        'base_quantity' => 2,
        'unit_price' => 10000,
        'discount_per_unit' => 500,
        'discount_total' => 1000,
        'line_total' => 19500,
    ]);

    Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('Discount: 500 each')
        ->assertSee('Tax/VAT')
        ->assertSee('Change')
        ->assertDontSee('Outstanding');
});

test('credit or partial receipt shows outstanding instead of change or balance', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $sale = makeCustomerReceiptSale($this->branch, $this->admin, [
        'subtotal' => 15000,
        'total_amount' => 15000,
        'paid_amount' => 10000,
        'balance_amount' => 5000,
        'change_amount' => 0,
        'payment_status' => 'partial',
    ], [
        'selling_unit_id' => $piece->id,
        'base_unit_id' => $piece->id,
        'quantity' => 1,
        'base_quantity' => 1,
        'unit_price' => 15000,
        'line_total' => 15000,
    ]);

    Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('Outstanding')
        ->assertSee('5,000')
        ->assertDontSee('Change')
        ->assertDontSee('Balance');
});

test('internal sale details preserve stock and conversion information', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $metre = Unit::where('short_name', 'm')->firstOrFail();
    $sale = makeCustomerReceiptSale($this->branch, $this->admin, [], [
        'selling_unit_id' => $metre->id,
        'base_unit_id' => $piece->id,
        'conversion_factor' => 3,
        'quantity' => 1.5,
        'base_quantity' => 0.5,
        'sold_from_label' => 'Main Warehouse',
    ]);

    Volt::test('sales.show', ['sale' => $sale])
        ->assertSee('Main Warehouse')
        ->assertSee('1.5 m')
        ->assertSee('0.5 pcs')
        ->assertSee('1 pcs = 3 m');
});

test('receipt offers compact printable 58mm and 80mm paper layouts', function () {
    $sale = makeCustomerReceiptSale($this->branch, $this->admin);

    $this->get(route('sales.receipt', ['sale' => $sale, 'paper' => 58]))
        ->assertOk()
        ->assertSee('data-paper-size="58"', false)
        ->assertSee('--receipt-width: 52mm', false)
        ->assertSee('max-width: 120px', false)
        ->assertSee('page-break-inside: avoid', false);

    $this->get(route('sales.receipt', ['sale' => $sale, 'paper' => 80]))
        ->assertOk()
        ->assertSee('data-paper-size="80"', false)
        ->assertSee('--receipt-width: 72mm', false)
        ->assertSee('max-width: 180px', false)
        ->assertSee('window.print()', false);
});

test('receipt header displays configured company branding in business identity order', function () {
    Setting::query()->firstOrFail()->update([
        'company_name' => 'Kariakoo Hardware',
        'company_tagline' => 'Building Solutions',
        'company_logo' => 'company-logos/kariakoo.png',
        'company_phone' => '+255 629 364 847',
        'alternate_phone' => '+255 754 123 456',
        'company_email' => 'sales@kariakoo.example',
        'company_address' => 'Mbeya - Ilomba',
        'company_website' => 'www.kariakoohardware.co.tz',
        'tin_number' => 'TIN-12345',
        'vrn_number' => 'VAT-67890',
        'show_tax_identifiers_on_receipt' => true,
    ]);
    $sale = makeCustomerReceiptSale($this->branch, $this->admin);

    $component = Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('storage/company-logos/kariakoo.png', false)
        ->assertSee('Kariakoo Hardware')
        ->assertSee('Building Solutions')
        ->assertSee('+255 629 364 847')
        ->assertSee('+255 754 123 456')
        ->assertSee('sales@kariakoo.example')
        ->assertSee('Mbeya - Ilomba')
        ->assertSee('www.kariakoohardware.co.tz')
        ->assertSee('TIN: TIN-12345')
        ->assertSee('VAT: VAT-67890')
        ->assertSee('SALES RECEIPT');

    $html = $component->html();

    expect(strpos($html, 'company-logos/kariakoo.png'))->toBeLessThan(strpos($html, 'Kariakoo Hardware'))
        ->and(strpos($html, 'Kariakoo Hardware'))->toBeLessThan(strpos($html, 'Building Solutions'))
        ->and(strpos($html, 'Building Solutions'))->toBeLessThan(strpos($html, '+255 629 364 847'))
        ->and(strpos($html, 'VAT: VAT-67890'))->toBeLessThan(strpos($html, 'SALES RECEIPT'));
});

test('receipt header omits missing branding fields and disabled tax identifiers without blank placeholders', function () {
    Setting::query()->firstOrFail()->update([
        'company_logo' => null,
        'company_tagline' => null,
        'alternate_phone' => null,
        'whatsapp_number' => null,
        'company_email' => null,
        'company_address' => null,
        'company_website' => null,
        'tin_number' => 'PRIVATE-TIN',
        'vrn_number' => 'PRIVATE-VAT',
        'show_tax_identifiers_on_receipt' => false,
    ]);
    $sale = makeCustomerReceiptSale($this->branch, $this->admin);

    Volt::test('sales.receipt', ['sale' => $sale])
        ->assertSee('SALES RECEIPT')
        ->assertDontSee('<img', false)
        ->assertDontSee('PRIVATE-TIN')
        ->assertDontSee('PRIVATE-VAT')
        ->assertDontSee('TIN:')
        ->assertDontSee('VAT:');
});

test('company settings save receipt branding fields for the current business', function () {
    Volt::test('settings.company')
        ->set('tagline', 'Professional Building Supplies')
        ->set('alternate_phone', '+255 700 111 222')
        ->set('website', 'www.hardex.example')
        ->set('show_tax_identifiers_on_receipt', true)
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::query()->findOrFail($this->admin->company_id);
    $settings = Setting::query()->firstOrFail();

    expect($company->tagline)->toBe('Professional Building Supplies')
        ->and($company->alternate_phone)->toBe('+255 700 111 222')
        ->and($company->website)->toBe('www.hardex.example')
        ->and($company->show_tax_identifiers_on_receipt)->toBeTrue()
        ->and($settings->company_tagline)->toBe('Professional Building Supplies')
        ->and($settings->alternate_phone)->toBe('+255 700 111 222')
        ->and($settings->company_website)->toBe('www.hardex.example')
        ->and($settings->show_tax_identifiers_on_receipt)->toBeTrue();
});

function makeCustomerReceiptSale(Branch $branch, User $user, array $saleOverrides = [], array $itemOverrides = []): Sale
{
    $template = Product::query()->firstOrFail();
    $sellingUnitId = $itemOverrides['selling_unit_id'] ?? $template->selling_unit_id ?? $template->unit_id;
    $baseUnitId = $itemOverrides['base_unit_id'] ?? $template->unit_id;
    $stockLocationId = StockLocation::where('branch_id', $branch->id)->value('id');
    $product = Product::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'stock_location_id' => $stockLocationId,
        'category_id' => $template->category_id,
        'measurement_type_id' => $itemOverrides['measurement_type_id']
            ?? MeasurementType::where('code', MeasurementType::COUNT)->value('id'),
        'unit_id' => $baseUnitId,
        'selling_unit_id' => $sellingUnitId,
        'product_size_id' => $itemOverrides['product_size_id'] ?? null,
        'name' => $itemOverrides['product_name'] ?? 'Receipt Product',
        'sku' => 'RECEIPT-'.uniqid(),
        'buying_price' => 5000,
        'selling_price' => $itemOverrides['unit_price'] ?? 10000,
        'conversion_factor' => $itemOverrides['conversion_factor'] ?? 1,
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
        'reorder_level' => 1,
        'status' => 'active',
    ]);

    $sale = Sale::query()->create(array_merge([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'sale_number' => 'RECEIPT-'.uniqid(),
        'sale_date' => today(),
        'sale_type' => 'retail',
        'subtotal' => 10000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 10000,
        'paid_amount' => 10000,
        'balance_amount' => 0,
        'change_amount' => 0,
        'payment_status' => 'paid',
        'status' => 'completed',
        'created_by' => $user->id,
        'sold_by' => $user->id,
    ], $saleOverrides));

    $sale->items()->create(array_merge([
        'company_id' => $branch->company_id,
        'product_id' => $product->id,
        'product_size_id' => $itemOverrides['product_size_id'] ?? null,
        'stock_location_id' => $stockLocationId,
        'selling_unit_id' => $sellingUnitId,
        'base_unit_id' => $baseUnitId,
        'conversion_factor' => 1,
        'sold_from_label' => null,
        'sale_type' => 'retail',
        'quantity' => 1,
        'base_quantity' => 1,
        'unit_cost' => 5000,
        'unit_price' => 10000,
        'discount_per_unit' => 0,
        'discount_amount' => 0,
        'discount_total' => 0,
        'gross_total' => 10000,
        'net_unit_price' => 10000,
        'net_total' => 10000,
        'tax_amount' => 0,
        'line_total' => 10000,
    ], collect($itemOverrides)->except(['product_name', 'measurement_type_id', 'product_size_id'])->all()));

    return $sale->refresh();
}
