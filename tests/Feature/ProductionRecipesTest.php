<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProductionRecipeCalculator;
use App\Services\ProductionRecipeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->actingAs($this->admin);
    $this->branch = Branch::query()->whereKey($this->admin->branch_id)->firstOrFail();

    $template = Product::query()->with(['unit', 'purchaseUnit'])->firstOrFail();
    $this->finished = $template->replicate();
    $this->finished->name = 'Recipe Heavy Block 6';
    $this->finished->sku = 'RECIPE-MFG-6';
    $this->finished->inventory_source = Product::INVENTORY_SOURCE_MANUFACTURED;
    $this->finished->save();

    $this->material = Product::query()->whereKeyNot($this->finished->id)->with(['unit', 'purchaseUnit'])->firstOrFail();
    $this->material->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED]);
    $this->materialUnit = Unit::query()->findOrFail($this->material->purchase_unit_id ?: $this->material->unit_id);
});

function recipeHeader(object $test, array $overrides = []): array
{
    return [
        'name' => 'Heavy Block 6 Recipe',
        'code' => 'BOM-HB6',
        'version' => '1',
        'product_id' => $test->finished->id,
        'output_quantity' => '1',
        'output_unit_id' => $test->finished->unit_id,
        'status' => ProductionRecipe::STATUS_DRAFT,
        'effective_from' => '2026-07-29',
        'effective_to' => '',
        'notes' => 'Test formula',
        ...$overrides,
    ];
}

function inventoryRecipeItem(object $test, array $overrides = []): array
{
    return [
        'cost_type' => ProductionRecipeItem::TYPE_INVENTORY,
        'material_product_id' => $test->material->id,
        'material_unit_id' => $test->materialUnit->id,
        'entry_mode' => ProductionRecipeItem::MODE_YIELD,
        'source_quantity' => '1',
        'yield_quantity' => '80',
        'unit_cost' => '',
        'notes' => '',
        ...$overrides,
    ];
}

function saveTestRecipe(object $test, array $header = [], array $items = []): ProductionRecipe
{
    return app(ProductionRecipeService::class)->save(
        recipeHeader($test, $header),
        $items ?: [inventoryRecipeItem($test)],
        $test->admin
    );
}

test('recipe route and menu require manufacturing and recipe permission', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(__('production.recipes.title'));
    $this->get(route('production.recipes.index'))->assertOk();

    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('dashboard'))->assertOk()->assertDontSee(__('production.recipes.title'));
    $this->get(route('production.recipes.index'))->assertForbidden();

    $this->company->update(['manufacturing_enabled' => true]);
    $cashier = User::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'is_system_owner' => false,
    ]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);

    $this->get(route('production.recipes.index'))->assertForbidden();
    Volt::test('production.recipes.index')->assertForbidden();
});

test('recipe can be created for a manufactured product using a purchased material', function () {
    $recipe = saveTestRecipe($this);

    expect($recipe->company_id)->toBe($this->company->id)
        ->and($recipe->product_id)->toBe($this->finished->id)
        ->and($recipe->items)->toHaveCount(1)
        ->and($recipe->items->first()->material_product_id)->toBe($this->material->id)
        ->and($recipe->items->first()->normalized_quantity)->toBe('0.012500000000');
});

test('purchased finished product and invalid output quantity are rejected', function () {
    expect(fn () => saveTestRecipe($this, ['product_id' => $this->material->id]))
        ->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, ['output_quantity' => '0']))
        ->toThrow(ValidationException::class);
});

test('inventory material validation rejects finished product duplicate material and zero yield', function () {
    expect(fn () => saveTestRecipe($this, [], [
        inventoryRecipeItem($this, ['material_product_id' => $this->finished->id]),
    ]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [
        inventoryRecipeItem($this),
        inventoryRecipeItem($this),
    ]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [
        inventoryRecipeItem($this, ['yield_quantity' => '0']),
    ]))->toThrow(ValidationException::class);
});

test('non inventory costs require a name and quantity or cost', function () {
    $invalid = [
        'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
        'cost_name' => '',
        'source_quantity' => '',
        'material_unit_id' => '',
        'unit_cost' => '',
    ];

    expect(fn () => saveTestRecipe($this, [], [$invalid]))
        ->toThrow(ValidationException::class);

    $invalid['cost_name'] = 'Electricity';
    expect(fn () => saveTestRecipe($this, [], [$invalid]))
        ->toThrow(ValidationException::class);
});

test('authoring metadata rejects invalid basis quantities costs and output', function () {
    expect(fn () => saveTestRecipe($this, [], [inventoryRecipeItem($this, [
        'authoring_basis' => 'guessed_basis',
        'authoring_quantity' => '1',
        'authoring_output_quantity' => '1',
    ])]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [inventoryRecipeItem($this, [
        'authoring_basis' => ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT,
        'authoring_quantity' => '-1',
        'authoring_output_quantity' => '1',
    ])]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [[
        'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
        'cost_name' => 'Electricity',
        'source_quantity' => '',
        'material_unit_id' => '',
        'unit_cost' => '20',
        'authoring_basis' => ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT,
        'authoring_unit_cost' => '-1',
        'authoring_output_quantity' => '1',
    ]]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [inventoryRecipeItem($this, [
        'authoring_basis' => ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT,
        'authoring_quantity' => '1',
        'authoring_output_quantity' => '0',
    ])]))->toThrow(ValidationException::class);
});

test('yield normalization preserves required precision', function () {
    $calculator = app(ProductionRecipeCalculator::class);

    expect($calculator->normalizeYield('1', '80'))->toBe('0.012500000000')
        ->and($calculator->normalizeYield('1', '42'))->toBe('0.023809523809')
        ->and($calculator->normalizeYield('1', '1200'))->toBe('0.000833333333')
        ->and(fn () => $calculator->normalizeYield('1', '0'))->toThrow(InvalidArgumentException::class);
});

test('calculator multiplies material quantities and monetary costs without float arithmetic', function () {
    $recipe = saveTestRecipe($this, [], [
        inventoryRecipeItem($this),
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Water',
            'source_quantity' => '5',
            'material_unit_id' => $this->materialUnit->id,
            'unit_cost' => '',
            'notes' => '',
        ],
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Electricity',
            'source_quantity' => '',
            'material_unit_id' => '',
            'unit_cost' => '20',
            'notes' => '',
        ],
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Labour',
            'source_quantity' => '',
            'material_unit_id' => '',
            'unit_cost' => '150',
            'notes' => '',
        ],
    ]);

    $result = app(ProductionRecipeCalculator::class)->calculate($recipe, '1440');

    expect($result['materials'][0]['required_quantity'])->toBe('18.000000000000')
        ->and($result['non_inventory_costs'][0]['required_quantity'])->toBe('7200.000000000000')
        ->and($result['non_inventory_costs'][1]['total_cost'])->toBe('28800.0000')
        ->and($result['direct_non_inventory_cost'])->toBe('244800.0000');
});

test('recipe details show configured equivalent and target non inventory values', function () {
    $recipe = saveTestRecipe($this, [
        'name' => 'Readable Non Inventory Recipe',
        'code' => 'BOM-READABLE-COSTS',
        'output_quantity' => '72',
    ], [
        inventoryRecipeItem($this),
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Water',
            'source_quantity' => '5',
            'material_unit_id' => $this->materialUnit->id,
            'unit_cost' => '',
            'notes' => '',
        ],
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Electricity',
            'source_quantity' => '',
            'material_unit_id' => '',
            'unit_cost' => '20',
            'notes' => '',
        ],
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Labour',
            'source_quantity' => '',
            'material_unit_id' => '',
            'unit_cost' => '150',
            'notes' => '',
        ],
    ]);
    $unit = $this->materialUnit->short_name;
    $outputUnit = $this->finished->unit->short_name;

    Volt::test('production.recipes.show', ['recipe' => $recipe])
        ->set('targetOutput', '720')
        ->call('calculate')
        ->assertSee('Configured:')
        ->assertSee('Equivalent:')
        ->assertSee("5 {$unit} per finished unit")
        ->assertSee("360 {$unit} per recipe output (72 {$outputUnit})")
        ->assertSee("3600 {$unit}")
        ->assertSee('TZS 20 per finished unit')
        ->assertSee("TZS 1,440 per recipe output (72 {$outputUnit})")
        ->assertSee('TZS 14,400')
        ->assertSee('TZS 150 per finished unit')
        ->assertSee("TZS 10,800 per recipe output (72 {$outputUnit})")
        ->assertSee('TZS 108,000')
        ->assertDontSee('TZS 0.28')
        ->assertDontSee('TZS 2.08');
});

test('activation deactivates previous version while drafts coexist', function () {
    $first = saveTestRecipe($this, ['status' => ProductionRecipe::STATUS_ACTIVE]);
    $second = saveTestRecipe($this, [
        'name' => 'Heavy Block 6 Recipe v2',
        'code' => 'BOM-HB6-V2',
        'version' => '2',
    ]);

    expect($first->refresh()->status)->toBe(ProductionRecipe::STATUS_ACTIVE)
        ->and($second->status)->toBe(ProductionRecipe::STATUS_DRAFT);

    app(ProductionRecipeService::class)->activate($second, $this->admin);

    expect($first->refresh()->status)->toBe(ProductionRecipe::STATUS_INACTIVE)
        ->and($second->refresh()->status)->toBe(ProductionRecipe::STATUS_ACTIVE)
        ->and(ProductionRecipe::query()->where('product_id', $this->finished->id)->where('status', 'active')->count())->toBe(1);
});

test('duplicate recipe creates a complete new draft version', function () {
    $recipe = saveTestRecipe($this, ['status' => ProductionRecipe::STATUS_ACTIVE]);
    $copy = app(ProductionRecipeService::class)->duplicate($recipe, $this->admin);

    expect($copy->id)->not->toBe($recipe->id)
        ->and($copy->status)->toBe(ProductionRecipe::STATUS_DRAFT)
        ->and($copy->version)->toBe('2')
        ->and($copy->items)->toHaveCount($recipe->items->count());
});

test('draft recipe edit route loads existing rows and updates Water basis without duplicating recipe', function () {
    $recipe = saveTestRecipe($this, [
        'name' => 'Editable Water Recipe',
        'code' => 'BOM-WATER-EDIT',
        'output_quantity' => '72',
        'notes' => 'Original header notes',
    ], [
        inventoryRecipeItem($this),
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
            'cost_name' => 'Water',
            'source_quantity' => '5',
            'material_unit_id' => $this->materialUnit->id,
            'unit_cost' => '20',
            'notes' => 'Water row notes',
        ],
    ]);
    $editUrl = route('production.recipes.edit', $recipe);
    $recipeCount = ProductionRecipe::query()->count();

    $this->get(route('production.recipes.index'))->assertOk()->assertSee($editUrl, escape: false);
    $this->get(route('production.recipes.show', $recipe))->assertOk()->assertSee($editUrl, escape: false);
    $this->get($editUrl)
        ->assertOk()
        ->assertSee('Edit Recipe')
        ->assertSee('Water')
        ->assertSee('Water row notes');

    $component = Volt::test('production.recipes.form', ['recipe' => $recipe])
        ->assertSet('recipe.id', $recipe->id)
        ->assertSet('name', 'Editable Water Recipe')
        ->assertSet('code', 'BOM-WATER-EDIT')
        ->assertSet('output_quantity', '72.00000000')
        ->assertSet('notes', 'Original header notes')
        ->assertSet('items.0.material_product_id', (string) $this->material->id)
        ->assertSet('items.1.cost_name', 'Water')
        ->assertSet('items.1.source_quantity', '5.000000000000')
        ->assertSet('items.1.material_unit_id', (string) $this->materialUnit->id)
        ->assertSet('items.1.unit_cost', '20.0000')
        ->assertSet('items.1.notes', 'Water row notes')
        ->assertSet('items.1.basis', 'finished_unit');

    $waterUuid = $component->get('items.1.uuid');

    $component
        ->set('items.1.basis', 'recipe_output')
        ->assertSet('items.1.basis', 'recipe_output')
        ->assertSet('items.1.uuid', $waterUuid)
        ->set('items.1.basis', 'finished_unit')
        ->assertSet('items.1.basis', 'finished_unit')
        ->assertSet('items.1.uuid', $waterUuid)
        ->set('name', 'Corrected Water Recipe')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production.recipes.show', $recipe));

    $recipe->refresh()->load('items');
    $water = $recipe->items->firstWhere('cost_name', 'Water');

    expect(ProductionRecipe::query()->count())->toBe($recipeCount)
        ->and($recipe->name)->toBe('Corrected Water Recipe')
        ->and($recipe->status)->toBe(ProductionRecipe::STATUS_DRAFT)
        ->and($recipe->items)->toHaveCount(2)
        ->and($water)->not->toBeNull()
        ->and($water->normalized_quantity)->toBe('5.000000000000')
        ->and($water->material_unit_id)->toBe($this->materialUnit->id)
        ->and($water->unit_cost)->toBe('20.0000')
        ->and($water->notes)->toBe('Water row notes');
});

test('active recipe cannot be edited directly', function () {
    $recipe = saveTestRecipe($this, ['status' => ProductionRecipe::STATUS_ACTIVE]);

    $this->get(route('production.recipes.edit', $recipe))->assertStatus(409);
    $this->get(route('production.recipes.show', $recipe))
        ->assertOk()
        ->assertDontSee(route('production.recipes.edit', $recipe), escape: false);

    expect(fn () => app(ProductionRecipeService::class)->save(
        recipeHeader($this, ['name' => 'Changed']),
        [inventoryRecipeItem($this)],
        $this->admin,
        $recipe
    ))->toThrow(ValidationException::class);
});

test('another company recipe and products are isolated', function () {
    $otherCompany = Company::query()->create([
        'company_name' => 'Recipe Isolation Ltd',
        'business_type' => 'Manufacturer',
        'phone' => '+255700811111',
        'whatsapp_number' => '+255700811111',
        'manufacturing_enabled' => true,
    ]);
    $attributes = $this->material->getAttributes();
    unset($attributes['id']);
    $attributes['company_id'] = $otherCompany->id;
    $attributes['name'] = 'Secret Raw Material';
    $attributes['sku'] = 'SECRET-RAW';
    $attributes['created_at'] = now();
    $attributes['updated_at'] = now();
    $otherProductId = DB::table('products')->insertGetId($attributes);
    $otherUnitId = DB::table('units')->insertGetId([
        'company_id' => $otherCompany->id,
        'name' => 'Secret Recipe Unit',
        'short_name' => 'secret-unit',
        'status' => 'active',
        'measurement_type_id' => $this->materialUnit->measurement_type_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherRecipeId = DB::table('production_recipes')->insertGetId([
        'company_id' => $otherCompany->id,
        'product_id' => $otherProductId,
        'name' => 'Secret Recipe',
        'output_quantity' => 1,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('production.recipes.index'))->assertOk()->assertDontSee('Secret Recipe');
    $this->get(route('production.recipes.show', $otherRecipeId))->assertNotFound();
    $this->get(route('production.recipes.edit', $otherRecipeId))->assertNotFound();

    expect(fn () => saveTestRecipe($this, [], [
        inventoryRecipeItem($this, ['material_product_id' => $otherProductId]),
    ]))->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, ['output_unit_id' => $otherUnitId]))
        ->toThrow(ValidationException::class);

    expect(fn () => saveTestRecipe($this, [], [
        inventoryRecipeItem($this, ['material_unit_id' => $otherUnitId]),
    ]))->toThrow(ValidationException::class);
});

test('recipe operations and calculator have no stock product or schedule side effects', function () {
    $movements = StockMovement::withoutGlobalScopes()->count();
    $stock = app(InventoryService::class)->getProductTotalStock($this->material->id, $this->branch->id);
    $buyingPrice = $this->finished->buying_price;
    $assignments = ProductionMachineAssignment::withoutGlobalScopes()->count();

    $recipe = saveTestRecipe($this);
    app(ProductionRecipeService::class)->activate($recipe, $this->admin);
    app(ProductionRecipeCalculator::class)->calculate($recipe->refresh(), '1440');
    app(ProductionRecipeService::class)->duplicate($recipe, $this->admin);

    expect(StockMovement::withoutGlobalScopes()->count())->toBe($movements)
        ->and(app(InventoryService::class)->getProductTotalStock($this->material->id, $this->branch->id))->toBe($stock)
        ->and($this->finished->refresh()->buying_price)->toBe($buyingPrice)
        ->and(ProductionMachineAssignment::withoutGlobalScopes()->count())->toBe($assignments);
});

test('recipe form uses stable uuid row keys and renders calculator details', function () {
    $component = Volt::test('production.recipes.form')
        ->call('addNonInventoryCost');

    expect($component->get('items.0.uuid'))->toBeString()->not->toBe('')
        ->and($component->get('items.1.uuid'))->toBeString()->not->toBe('')
        ->and($component->get('items.0.uuid'))->not->toBe($component->get('items.1.uuid'));

    $recipe = saveTestRecipe($this);
    Volt::test('production.recipes.show', ['recipe' => $recipe])
        ->assertSee('Requirement Calculator')
        ->assertSee($this->material->name);
});

test('new recipe renders an editable inventory material row and working add button by default', function () {
    $component = Volt::test('production.recipes.form')
        ->assertSet('items.0.cost_type', ProductionRecipeItem::TYPE_INVENTORY)
        ->assertSet('items.0.basis', 'recipe_output')
        ->assertSeeInOrder([
            __('production.recipes.form.inventory_materials'),
            __('production.recipes.form.inventory_materials_help'),
            __('production.recipes.form.add_inventory_material'),
            __('production.recipes.form.non_inventory_costs'),
        ])
        ->assertSee(__('production.recipes.form.material_product'))
        ->assertSee(__('production.recipes.form.quantity'))
        ->assertSee(__('production.recipes.form.unit'))
        ->assertSee(__('production.recipes.form.recipe_basis'))
        ->assertSee(__('production.recipes.form.notes'))
        ->assertSee(__('production.recipes.form.remove'))
        ->assertSeeHtml('wire:model.live="items.0.material_product_id"')
        ->assertSeeHtml('wire:model.live.debounce.300ms="items.0.source_quantity"')
        ->assertSeeHtml('min="0.000000000001" step="any"')
        ->assertDontSeeHtml('wire:model.live="items.0.material_unit_id"')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('wire:model.live="items.0.basis"')
        ->assertSeeHtml('wire:model.blur="items.0.notes"')
        ->assertSeeHtml('wire:click="addInventoryMaterial"')
        ->assertSeeHtml('wire:target="addInventoryMaterial"')
        ->assertSeeHtml('wire:click="addNonInventoryCost"')
        ->assertSeeHtml('wire:target="addNonInventoryCost"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('border-slate-300 bg-white')
        ->assertSeeHtml('text-slate-900')
        ->assertSeeHtml('dark:border-slate-700 dark:bg-slate-900 dark:text-white')
        ->assertSeeHtml('hover:bg-slate-50 active:bg-slate-100')
        ->assertSeeHtml('dark:hover:bg-slate-800 dark:active:bg-slate-700')
        ->assertSeeHtml('focus:ring-cyan-500 focus:ring-offset-2')
        ->assertSeeHtml('disabled:cursor-not-allowed disabled:opacity-50')
        ->assertSeeHtml('text-cyan-700 dark:text-cyan-300');

    $firstUuid = $component->get('items.0.uuid');

    $component->call('addInventoryMaterial')
        ->assertCount('items', 2)
        ->assertSet('items.1.cost_type', ProductionRecipeItem::TYPE_INVENTORY);

    expect($component->get('items.1.uuid'))->not->toBe($firstUuid);
});

test('inventory material selection auto fills the base stock unit and rejects unit tampering', function () {
    $cubicMetre = Unit::query()->where('short_name', 'm³')->firstOrFail();
    $bag = Unit::query()->where('short_name', 'bag')->firstOrFail();

    $moramu = $this->material->replicate();
    $moramu->name = 'Moramu Mweusi';
    $moramu->sku = 'RAW-MORAMU-UI';
    $moramu->unit_id = $cubicMetre->id;
    $moramu->purchase_unit_id = $bag->id;
    $moramu->save();

    $cement = $this->material->replicate();
    $cement->name = 'Cement';
    $cement->sku = 'RAW-CEMENT-UI';
    $cement->unit_id = $bag->id;
    $cement->purchase_unit_id = $bag->id;
    $cement->save();

    $component = Volt::test('production.recipes.form')
        ->set('items.0.source_quantity', '1')
        ->set('items.0.basis', 'recipe_output')
        ->set('items.0.material_product_id', (string) $moramu->id)
        ->assertSet('items.0.material_unit_id', (string) $cubicMetre->id)
        ->assertSee('Cubic Metre (m³)')
        ->set('items.0.material_unit_id', (string) $bag->id)
        ->assertSet('items.0.material_unit_id', (string) $cubicMetre->id)
        ->set('items.0.material_product_id', (string) $cement->id)
        ->assertSet('items.0.material_unit_id', (string) $bag->id)
        ->assertSet('items.0.source_quantity', '1')
        ->assertSet('items.0.basis', 'recipe_output')
        ->assertSee('Bag (bag)');

    $prepared = $component->instance()->itemsForPersistence();

    expect($prepared[0]['material_unit_id'])->toBe((string) $bag->id);
});

test('inventory product without a usable company stock unit is blocked with a clear error', function () {
    DB::statement('PRAGMA defer_foreign_keys = ON');

    $unconfigured = $this->material->replicate();
    $unconfigured->name = 'Unconfigured Raw Material';
    $unconfigured->sku = 'RAW-NO-STOCK-UNIT';
    $unconfigured->unit_id = 999999;
    $unconfigured->purchase_unit_id = 999999;
    $unconfigured->saveQuietly();

    Volt::test('production.recipes.form')
        ->set('name', 'Missing Unit Recipe')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_quantity', '1')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $unconfigured->id)
        ->set('items.0.source_quantity', '1')
        ->assertSet('items.0.material_unit_id', '')
        ->assertSee(__('production.recipes.validation.stock_unit_missing'))
        ->call('save')
        ->assertHasErrors(['items.0.material_unit_id']);
});

test('recipe explanations and header grammar use live products outputs and BCMath equivalents', function () {
    app()->setLocale('en');

    $component = Volt::test('production.recipes.form')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('output_quantity', '1')
        ->assertSee("One recipe produces 1 finished unit of {$this->finished->name}.")
        ->set('output_quantity', '72')
        ->assertSee("One recipe produces 72 finished units of {$this->finished->name}.")
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.source_quantity', '1')
        ->set('items.0.basis', 'recipe_output');

    $unit = $this->material->unit->short_name;
    $outputUnit = $this->finished->unit->short_name;

    $component
        ->assertSee("This recipe uses 1 {$unit} of {$this->material->name} to produce 72 {$outputUnit} of {$this->finished->name}.")
        ->assertSee("Equivalent: 0.013888888888 {$unit} per finished unit.")
        ->set('items.0.source_quantity', '5')
        ->set('items.0.basis', 'finished_unit')
        ->assertSee("This recipe uses 5 {$unit} of {$this->material->name} for each {$this->finished->name}.")
        ->assertSee("Equivalent: 360 {$unit} per recipe output of 72 {$outputUnit}.");
});

test('non inventory explanations show water quantity and electricity and labour TZS equivalents', function () {
    app()->setLocale('en');

    $component = Volt::test('production.recipes.form')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items', [])
        ->call('addNonInventoryCost')
        ->set('items.0.cost_name', 'Water')
        ->set('items.0.source_quantity', '5')
        ->set('items.0.material_unit_id', (string) $this->materialUnit->id)
        ->set('items.0.basis', 'finished_unit')
        ->call('addNonInventoryCost')
        ->set('items.1.cost_name', 'Electricity')
        ->set('items.1.unit_cost', '20')
        ->set('items.1.basis', 'finished_unit')
        ->call('addNonInventoryCost')
        ->set('items.2.cost_name', 'Labour')
        ->set('items.2.unit_cost', '150')
        ->set('items.2.basis', 'finished_unit');

    $unit = $this->materialUnit->short_name;
    $outputUnit = $this->finished->unit->short_name;

    $component
        ->assertSee("This recipe uses 5 {$unit} of Water for each {$this->finished->name}.")
        ->assertSee("Equivalent: 360 {$unit} per recipe output of 72 {$outputUnit}.")
        ->assertSee("Electricity costs TZS 20 per {$this->finished->name}.")
        ->assertSee("Equivalent: TZS 1,440 per recipe output of 72 {$outputUnit}.")
        ->assertSee("Labour costs TZS 150 per {$this->finished->name}.")
        ->assertSee("Equivalent: TZS 10,800 per recipe output of 72 {$outputUnit}.")
        ->assertSeeHtml('wire:model.blur="items.1.unit_cost"');
});

test('changing finished product and output preserves rows and recalculates explanations', function () {
    app()->setLocale('en');

    $otherFinished = $this->finished->replicate();
    $otherFinished->name = 'Recipe Paver';
    $otherFinished->sku = 'RECIPE-PAVER-UI';
    $otherFinished->save();

    $component = Volt::test('production.recipes.form')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('output_quantity', '72')
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.source_quantity', '5')
        ->set('items.0.basis', 'finished_unit');

    $uuid = $component->get('items.0.uuid');
    $unit = $this->material->unit->short_name;

    $component
        ->assertSee("Equivalent: 360 {$unit} per recipe output")
        ->set('product_id', (string) $otherFinished->id)
        ->assertSet('items.0.uuid', $uuid)
        ->assertSet('items.0.source_quantity', '5')
        ->assertSee("for each {$otherFinished->name}.")
        ->set('output_quantity', '100')
        ->assertSet('items.0.uuid', $uuid)
        ->assertSet('items.0.source_quantity', '5')
        ->assertSee("Equivalent: 500 {$unit} per recipe output");
});

test('recipe form repairs missing ui row keys after livewire hydration and validation', function () {
    $component = Volt::test('production.recipes.form');

    $partialRow = $component->get('items.0');
    unset($partialRow['uuid'], $partialRow['entry_mode']);

    $component->set('items.0', $partialRow)
        ->assertSet('items.0.entry_mode', ProductionRecipeItem::MODE_PER_OUTPUT)
        ->call('save')
        ->assertHasErrors(['product_id']);

    expect($component->get('items.0.uuid'))->toBeString()->not->toBe('');
});

test('recipe form saves high precision material quantities without rounding the typed value', function () {
    $quantities = [
        '0.33488372093' => '0.334883720930',
        '0.533333333333' => '0.533333333333',
        '0.013888888889' => '0.013888888889',
        '0.004651162791' => '0.004651162791',
        '0.000833333333' => '0.000833333333',
    ];

    foreach ($quantities as $entered => $stored) {
        $code = 'PRECISION-'.str_replace('.', '-', $entered);

        Volt::test('production.recipes.form')
            ->set('name', "Precision {$entered}")
            ->set('code', $code)
            ->set('product_id', (string) $this->finished->id)
            ->set('output_quantity', '1')
            ->set('output_unit_id', (string) $this->finished->unit_id)
            ->set('items.0.material_product_id', (string) $this->material->id)
            ->set('items.0.source_quantity', $entered)
            ->set('items.0.basis', 'finished_unit')
            ->call('save')
            ->assertHasNoErrors();

        $recipe = ProductionRecipe::query()->where('code', $code)->firstOrFail();

        expect($recipe->items->first()->source_quantity)->toBe($stored)
            ->and($recipe->items->first()->normalized_quantity)->toBe($stored);
    }
});

test('recipe form still rejects zero negative non numeric and over precision quantities', function () {
    foreach (['0', '-0.000833333333', 'not-a-number'] as $invalid) {
        Volt::test('production.recipes.form')
            ->set('name', 'Invalid Precision Recipe')
            ->set('product_id', (string) $this->finished->id)
            ->set('output_quantity', '1')
            ->set('output_unit_id', (string) $this->finished->unit_id)
            ->set('items.0.material_product_id', (string) $this->material->id)
            ->set('items.0.source_quantity', $invalid)
            ->set('items.0.basis', 'finished_unit')
            ->call('save')
            ->assertHasErrors(['items.0.source_quantity']);
    }

    Volt::test('production.recipes.form')
        ->set('name', 'Over Precision Recipe')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_quantity', '1')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.source_quantity', '0.1234567890123')
        ->set('items.0.basis', 'finished_unit')
        ->call('save')
        ->assertHasErrors(['items.0.source_quantity']);
});

test('new authoring rows default to recipe output basis and show clear factory guidance', function () {
    Volt::test('production.recipes.form')
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->call('addNonInventoryCost')
        ->assertSet('items.0.basis', 'recipe_output')
        ->assertSet('items.1.basis', 'recipe_output')
        ->assertSeeInOrder([
            'Recipe Header',
            __('production.recipes.form.inventory_materials'),
            __('production.recipes.form.inventory_materials_help'),
            __('production.recipes.form.add_inventory_material'),
            __('production.recipes.form.non_inventory_costs'),
            __('production.recipes.form.add_non_inventory_cost'),
        ])
        ->assertSee(__('production.recipes.form.material_product'))
        ->assertSee(__('production.recipes.form.notes'))
        ->assertSee(__('production.recipes.form.recipe_output'))
        ->assertSee(__('production.recipes.form.per_one_finished_unit'))
        ->assertSee(__('production.recipes.form.per_recipe_output'))
        ->assertSee(__('production.recipes.form.inventory_tooltip'))
        ->assertSee(__('production.recipes.form.non_inventory_tooltip'));
});

test('old yield recipes reopen as equivalent normalized per unit authoring values', function () {
    $recipe = saveTestRecipe($this);
    $before = app(ProductionRecipeCalculator::class)->calculate($recipe, '80');

    expect($recipe->items->first()->authoring_basis)->toBeNull()
        ->and($recipe->items->first()->authoring_quantity)->toBeNull()
        ->and($recipe->items->first()->authoring_unit_cost)->toBeNull()
        ->and($recipe->items->first()->authoring_output_quantity)->toBeNull();

    $component = Volt::test('production.recipes.form', ['recipe' => $recipe])
        ->assertSet('items.0.basis', 'finished_unit')
        ->assertSet('items.0.source_quantity', '0.012500000000')
        ->assertSet('items.0.legacy_authoring', true)
        ->assertSee(__('production.recipes.validation.legacy_authoring_unavailable'));

    Volt::test('production.recipes.show', ['recipe' => $recipe])
        ->assertSee('0.0125 '.$this->materialUnit->short_name.' per finished unit')
        ->assertSee(__('production.recipes.validation.legacy_authoring_unavailable'));

    $prepared = $component->instance()->itemsForPersistence();
    $after = app(ProductionRecipeCalculator::class)->calculate($recipe->refresh(), '80');

    expect($prepared[0]['entry_mode'])->toBe(ProductionRecipeItem::MODE_PER_OUTPUT)
        ->and($prepared[0]['source_quantity'])->toBe('0.012500000000')
        ->and($before['materials'][0]['required_quantity'])->toBe('1.000000000000')
        ->and($after)->toBe($before);
});

test('recipe output and finished unit bases produce identical normalized requirements', function () {
    $perRecipeComponent = Volt::test('production.recipes.form')
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.material_unit_id', (string) $this->materialUnit->id)
        ->set('items.0.source_quantity', '360')
        ->set('items.0.basis', 'recipe_output');

    $perUnitComponent = Volt::test('production.recipes.form')
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.material_unit_id', (string) $this->materialUnit->id)
        ->set('items.0.source_quantity', '5')
        ->set('items.0.basis', 'finished_unit');

    $perRecipeComponent->assertSee(__('production.recipes.form.equivalent_quantity_per_unit', [
        'quantity' => '5',
        'unit' => $this->material->unit->short_name,
    ]));
    $perUnitComponent->assertSee(__('production.recipes.form.equivalent_quantity_per_output', [
        'quantity' => '360',
        'unit' => $this->material->unit->short_name,
        'output' => '72',
        'output_unit' => $this->finished->unit->short_name,
    ]));

    $perRecipeItem = $perRecipeComponent->instance()->itemsForPersistence()[0];
    $perUnitItem = $perUnitComponent->instance()->itemsForPersistence()[0];
    $recipeFromTotal = app(ProductionRecipeService::class)->save(
        recipeHeader($this, ['name' => 'Total Basis Recipe', 'code' => 'BOM-TOTAL', 'output_quantity' => '72']),
        [$perRecipeItem],
        $this->admin
    );
    $recipeFromUnit = app(ProductionRecipeService::class)->save(
        recipeHeader($this, ['name' => 'Unit Basis Recipe', 'code' => 'BOM-UNIT', 'output_quantity' => '72']),
        [$perUnitItem],
        $this->admin
    );

    $totalCalculation = app(ProductionRecipeCalculator::class)->calculate($recipeFromTotal, '72');
    $unitCalculation = app(ProductionRecipeCalculator::class)->calculate($recipeFromUnit, '72');

    expect($perRecipeItem['source_quantity'])->toBe('5.000000000000')
        ->and($perUnitItem['source_quantity'])->toBe('5')
        ->and($recipeFromTotal->items->first()->normalized_quantity)->toBe('5.000000000000')
        ->and($recipeFromUnit->items->first()->normalized_quantity)->toBe('5.000000000000')
        ->and($recipeFromTotal->items->first()->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT)
        ->and($recipeFromTotal->items->first()->authoring_quantity)->toBe('360.000000000000')
        ->and($recipeFromTotal->items->first()->authoring_output_quantity)->toBe('72.000000000000')
        ->and($recipeFromUnit->items->first()->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT)
        ->and($recipeFromUnit->items->first()->authoring_quantity)->toBe('5.000000000000')
        ->and($recipeFromUnit->items->first()->authoring_output_quantity)->toBe('72.000000000000')
        ->and($totalCalculation['materials'][0]['required_quantity'])->toBe('360.000000000000')
        ->and($unitCalculation['materials'][0]['required_quantity'])->toBe('360.000000000000');
});

test('non inventory quantity and cost bases normalize identically without changing costing', function () {
    $perRecipeComponent = Volt::test('production.recipes.form')
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items', [])
        ->call('addNonInventoryCost')
        ->set('items.0.cost_name', 'Water and Utilities')
        ->set('items.0.source_quantity', '360')
        ->set('items.0.material_unit_id', (string) $this->materialUnit->id)
        ->set('items.0.unit_cost', '1440')
        ->set('items.0.basis', 'recipe_output');

    $perUnitComponent = Volt::test('production.recipes.form')
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items', [])
        ->call('addNonInventoryCost')
        ->set('items.0.cost_name', 'Water and Utilities')
        ->set('items.0.source_quantity', '5')
        ->set('items.0.material_unit_id', (string) $this->materialUnit->id)
        ->set('items.0.unit_cost', '20')
        ->set('items.0.basis', 'finished_unit');

    $totalItem = $perRecipeComponent->instance()->itemsForPersistence()[0];
    $unitItem = $perUnitComponent->instance()->itemsForPersistence()[0];
    $totalRecipe = app(ProductionRecipeService::class)->save(
        recipeHeader($this, ['name' => 'Total Cost Basis', 'code' => 'COST-TOTAL', 'output_quantity' => '72']),
        [$totalItem],
        $this->admin
    );
    $unitRecipe = app(ProductionRecipeService::class)->save(
        recipeHeader($this, ['name' => 'Unit Cost Basis', 'code' => 'COST-UNIT', 'output_quantity' => '72']),
        [$unitItem],
        $this->admin
    );

    $totalCalculation = app(ProductionRecipeCalculator::class)->calculate($totalRecipe, '72');
    $unitCalculation = app(ProductionRecipeCalculator::class)->calculate($unitRecipe, '72');

    expect($totalItem['source_quantity'])->toBe('5.000000000000')
        ->and($totalItem['unit_cost'])->toBe('20.0000')
        ->and($totalRecipe->items->first()->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT)
        ->and($totalRecipe->items->first()->authoring_quantity)->toBe('360.000000000000')
        ->and($totalRecipe->items->first()->authoring_unit_cost)->toBe('1440.00000000')
        ->and($totalRecipe->items->first()->authoring_output_quantity)->toBe('72.000000000000')
        ->and($unitRecipe->items->first()->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT)
        ->and($unitRecipe->items->first()->authoring_quantity)->toBe('5.000000000000')
        ->and($unitRecipe->items->first()->authoring_unit_cost)->toBe('20.00000000')
        ->and($unitRecipe->items->first()->authoring_output_quantity)->toBe('72.000000000000')
        ->and($totalCalculation['non_inventory_costs'][0]['required_quantity'])->toBe('360.000000000000')
        ->and($unitCalculation['non_inventory_costs'][0]['required_quantity'])->toBe('360.000000000000')
        ->and($totalCalculation['direct_non_inventory_cost'])->toBe('1440.0000')
        ->and($unitCalculation['direct_non_inventory_cost'])->toBe('1440.0000');
});

test('authoring metadata restores exact draft values and recalculates normalized values when output changes', function () {
    $component = Volt::test('production.recipes.form')
        ->set('name', 'Persistent Authoring Recipe')
        ->set('code', 'AUTHORING-PERSIST')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.source_quantity', '1')
        ->set('items.0.basis', 'recipe_output')
        ->call('addNonInventoryCost')
        ->set('items.1.cost_name', 'Electricity')
        ->set('items.1.unit_cost', '1440')
        ->set('items.1.basis', 'recipe_output')
        ->call('save')
        ->assertHasNoErrors();

    $recipe = ProductionRecipe::query()->where('code', 'AUTHORING-PERSIST')->firstOrFail()->load('items');
    $material = $recipe->items->firstWhere('cost_type', ProductionRecipeItem::TYPE_INVENTORY);
    $electricity = $recipe->items->firstWhere('cost_name', 'Electricity');

    expect($material->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT)
        ->and($material->authoring_quantity)->toBe('1.000000000000')
        ->and($material->normalized_quantity)->toBe('0.013888888888')
        ->and($electricity->authoring_basis)->toBe(ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT)
        ->and($electricity->authoring_unit_cost)->toBe('1440.00000000')
        ->and($electricity->unit_cost)->toBe('20.0000');

    $edit = Volt::test('production.recipes.form', ['recipe' => $recipe])
        ->assertSet('items.0.source_quantity', '1.000000000000')
        ->assertSet('items.0.basis', 'recipe_output')
        ->assertSet('items.1.unit_cost', '1440.00000000')
        ->assertSet('items.1.basis', 'recipe_output');

    $materialUuid = $edit->get('items.0.uuid');
    $electricityUuid = $edit->get('items.1.uuid');

    $edit->set('output_quantity', '80')
        ->assertSet('items.0.source_quantity', '1.000000000000')
        ->assertSet('items.0.basis', 'recipe_output')
        ->assertSet('items.0.uuid', $materialUuid)
        ->assertSet('items.1.unit_cost', '1440.00000000')
        ->assertSet('items.1.basis', 'recipe_output')
        ->assertSet('items.1.uuid', $electricityUuid)
        ->call('save')
        ->assertHasNoErrors();

    $recipe->refresh()->load('items');
    $material = $recipe->items->firstWhere('cost_type', ProductionRecipeItem::TYPE_INVENTORY);
    $electricity = $recipe->items->firstWhere('cost_name', 'Electricity');

    expect($recipe->output_quantity)->toBe('80.00000000')
        ->and($material->authoring_quantity)->toBe('1.000000000000')
        ->and($material->authoring_output_quantity)->toBe('80.000000000000')
        ->and($material->normalized_quantity)->toBe('0.012500000000')
        ->and($electricity->authoring_unit_cost)->toBe('1440.00000000')
        ->and($electricity->authoring_output_quantity)->toBe('80.000000000000')
        ->and($electricity->unit_cost)->toBe('18.0000');
});

test('recipe details use original per recipe values as configured and normalized values as equivalents', function () {
    $component = Volt::test('production.recipes.form')
        ->set('name', 'Authoring Details Recipe')
        ->set('code', 'AUTHORING-DETAILS')
        ->set('product_id', (string) $this->finished->id)
        ->set('output_quantity', '72')
        ->set('output_unit_id', (string) $this->finished->unit_id)
        ->set('items.0.material_product_id', (string) $this->material->id)
        ->set('items.0.source_quantity', '1')
        ->set('items.0.basis', 'recipe_output')
        ->call('addNonInventoryCost')
        ->set('items.1.cost_name', 'Electricity')
        ->set('items.1.unit_cost', '1440')
        ->set('items.1.basis', 'recipe_output')
        ->call('save')
        ->assertHasNoErrors();

    $recipe = ProductionRecipe::query()->where('code', 'AUTHORING-DETAILS')->firstOrFail();
    $outputUnit = $this->finished->unit->short_name;

    Volt::test('production.recipes.show', ['recipe' => $recipe])
        ->set('targetOutput', '720')
        ->call('calculate')
        ->assertSee('1 '.$this->materialUnit->short_name.' per recipe output')
        ->assertSee('0.013888888888 '.$this->materialUnit->short_name.' per finished unit')
        ->assertSee('TZS 1,440 per recipe output')
        ->assertSee('TZS 20 per finished unit')
        ->assertSee('TZS 14,400')
        ->assertDontSee("TZS 1,440 per recipe output (72 {$outputUnit})")
        ->assertDontSee(__('production.recipes.validation.legacy_authoring_unavailable'));
});
