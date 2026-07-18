<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Unit;

test('product can display a reusable size without storing it in the product name', function () {
    $company = Company::query()->create([
        'company_name' => 'HARDEX Test Hardware',
        'business_type' => 'Hardware Store',
        'phone' => '255700000000',
        'whatsapp_number' => '255700000000',
    ]);

    $category = Category::query()->create([
        'company_id' => $company->id,
        'name' => 'Pipe',
        'code' => 'PIPE',
        'status' => 'active',
    ]);

    $unit = Unit::query()->create([
        'company_id' => $company->id,
        'name' => 'Piece',
        'short_name' => 'pc',
        'status' => 'active',
    ]);

    $size = ProductSize::query()->create([
        'company_id' => $company->id,
        'name' => '2 × 4 (2mm)',
        'symbol' => '2 × 4 (2mm)',
        'description' => 'Two inch by four inch, 2mm thickness',
        'status' => 'active',
    ]);

    $product = Product::query()->create([
        'company_id' => $company->id,
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'product_size_id' => $size->id,
        'name' => 'PVC Square Tube',
        'sku' => 'PVC-SQ-TUBE',
        'buying_price' => 1000,
        'selling_price' => 1500,
        'reorder_level' => 1,
        'status' => 'active',
    ])->fresh(['size']);

    expect($product->name)->toBe('PVC Square Tube')
        ->and($product->sizeLabel())->toBe('2 × 4 (2mm)')
        ->and($product->displayNameWithSize())->toBe('PVC Square Tube - 2 × 4 (2mm)');
});
