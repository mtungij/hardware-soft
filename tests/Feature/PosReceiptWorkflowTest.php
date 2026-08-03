<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    [$this->location, $this->product] = posReceiptStock($this->branch, $this->admin);
    $this->actingAs($this->admin);
});

test('Pos successful checkout creates one sale clears cart and redirects to receipt preview', function () {
    $component = posReceiptComponent($this->location, $this->product);
    $submissionToken = $component->get('submission_token');

    $component->call('completeSale');

    $sale = Sale::query()->where('idempotency_key', $submissionToken)->firstOrFail();

    $component
        ->assertRedirect(route('sales.receipt', $sale))
        ->assertSet('cart', [])
        ->assertSet('customer_id', '')
        ->assertSet('payments.0.amount', '0')
        ->assertSet('processing', false);

    expect(Sale::query()->where('idempotency_key', $submissionToken)->count())->toBe(1)
        ->and($component->get('submission_token'))->not->toBe($submissionToken);
});

test('Pos failed checkout keeps cart and does not create or redirect to a receipt', function () {
    $component = posReceiptComponent($this->location, $this->product)
        ->set('cart.0.quantity', '100');
    $cart = $component->get('cart');
    $saleCount = Sale::query()->count();

    $component
        ->call('completeSale')
        ->assertHasErrors(['cart.0.quantity'])
        ->assertSet('cart', $cart)
        ->assertSet('processing', false)
        ->assertNoRedirect();

    expect(Sale::query()->count())->toBe($saleCount);
});

test('Pos repeated submission token returns the committed sale without duplicating stock movement', function () {
    $service = app(InventoryService::class);
    $token = (string) str()->uuid();
    $cart = [[
        'product_id' => $this->product->id,
        'sale_type' => 'retail',
        'quantity' => 1,
        'unit_price' => 100,
        'discount_amount' => 0,
        'tax_amount' => 0,
    ]];
    $payments = [['payment_method' => 'cash', 'amount' => 100, 'reference_number' => null]];

    $first = $service->completeSale(
        $cart,
        $payments,
        null,
        $this->location->id,
        $this->branch->id,
        $this->admin->id,
        idempotencyKey: $token,
    );
    $second = $service->completeSale(
        $cart,
        $payments,
        null,
        $this->location->id,
        $this->branch->id,
        $this->admin->id,
        idempotencyKey: $token,
    );

    expect($second->id)->toBe($first->id)
        ->and(Sale::query()->where('idempotency_key', $token)->count())->toBe(1)
        ->and(StockMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $first->id)
            ->where('movement_type', 'sale_out')
            ->count())->toBe(1);
});

test('Receipt preview is responsive offers both paper widths and print return fallbacks', function () {
    $sale = posReceiptSale($this->location, $this->product, $this->branch, $this->admin);

    $this->get(route('sales.receipt', $sale))
        ->assertOk()
        ->assertSee('class="receipt-page"', false)
        ->assertSee('--receipt-preview-width: 240px', false)
        ->assertSee('--receipt-preview-width: 320px', false)
        ->assertSee('width: min(100%, var(--receipt-preview-width))', false)
        ->assertSee('@media (max-width: 767px)', false)
        ->assertSee('overflow-x: hidden', false)
        ->assertSee('data-paper-selector="58"', false)
        ->assertSee('data-paper-selector="80"', false)
        ->assertSee('data-skip-printing', false)
        ->assertSee('data-start-new-sale', false)
        ->assertSee("window.addEventListener('afterprint'", false)
        ->assertSee('window.location.assign(window.hardexReceiptReturnUrl)', false)
        ->assertSee('.receipt-no-print', false)
        ->assertSee('display: none !important', false)
        ->assertSee($this->admin->company?->company_name ?: 'Hardex POS');
});

test('Receipt skip printing returns to Pos with sale number success flash', function () {
    $sale = posReceiptSale($this->location, $this->product, $this->branch, $this->admin);

    $this->get(route('sales.receipt.complete', $sale))
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, $sale->sale_number)
            && str_contains($message, 'Mauzo yamekamilika'));

    $this->get(route('pos.index'))
        ->assertOk()
        ->assertSee($sale->sale_number)
        ->assertSee('Mauzo yamekamilika');
});

function posReceiptComponent(StockLocation $location, Product $product)
{
    return Volt::test('pos.index')
        ->set('stock_location_id', (string) $location->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'stock_location_id' => $location->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '1',
            'unit_price' => '100',
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]])
        ->set('payments', [['payment_method' => 'cash', 'amount' => '100', 'reference_number' => '']]);
}

function posReceiptStock(Branch $branch, User $user): array
{
    $location = StockLocation::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'POS Receipt Workflow',
        'code' => 'POS-RECEIPT-'.uniqid(),
        'type' => 'dispensing',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'can_issue_stock' => true,
    ]);
    $product = Product::query()->firstOrFail();
    $product->update([
        'buying_price' => 50,
        'selling_price' => 100,
        'wholesale_price' => 90,
        'status' => 'active',
    ]);
    StockMovement::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 50,
        'unit_price' => 100,
        'created_by' => $user->id,
        'movement_date' => today(),
    ]);

    return [$location, $product];
}

function posReceiptSale(StockLocation $location, Product $product, Branch $branch, User $user): Sale
{
    return app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 100, 'reference_number' => null]],
        null,
        $location->id,
        $branch->id,
        $user->id,
        idempotencyKey: (string) str()->uuid(),
    );
}
