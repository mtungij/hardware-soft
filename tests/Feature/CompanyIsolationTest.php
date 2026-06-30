<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;

test('company users only see their company records and new records are stamped automatically', function () {
    $companyA = Company::create([
        'company_name' => 'Company A',
        'business_type' => 'Hardware Store',
        'phone' => '+255 700 100 100',
        'whatsapp_number' => '+255 700 100 100',
    ]);
    $companyB = Company::create([
        'company_name' => 'Company B',
        'business_type' => 'Hardware Store',
        'phone' => '+255 700 200 200',
        'whatsapp_number' => '+255 700 200 200',
    ]);

    Branch::create(['company_id' => $companyA->id, 'name' => 'A Main', 'code' => 'MAIN']);
    Branch::create(['company_id' => $companyB->id, 'name' => 'B Main', 'code' => 'MAIN']);

    $user = User::factory()->create([
        'company_id' => $companyA->id,
        'status' => 'active',
    ]);

    $this->actingAs($user);

    expect(Branch::pluck('name')->all())->toBe(['A Main']);

    $unit = Unit::create([
        'name' => 'Piece',
        'short_name' => 'pcs',
        'status' => 'active',
    ]);

    expect($unit->company_id)->toBe($companyA->id);
});

test('system owners bypass company scope', function () {
    $companyA = Company::create([
        'company_name' => 'Company A',
        'business_type' => 'Hardware Store',
        'phone' => '+255 700 100 100',
        'whatsapp_number' => '+255 700 100 100',
    ]);
    $companyB = Company::create([
        'company_name' => 'Company B',
        'business_type' => 'Hardware Store',
        'phone' => '+255 700 200 200',
        'whatsapp_number' => '+255 700 200 200',
    ]);

    Branch::create(['company_id' => $companyA->id, 'name' => 'A Main', 'code' => 'MAIN']);
    Branch::create(['company_id' => $companyB->id, 'name' => 'B Main', 'code' => 'MAIN']);

    $owner = User::factory()->create([
        'company_id' => $companyA->id,
        'is_system_owner' => true,
        'status' => 'active',
    ]);

    $this->actingAs($owner);

    expect(Branch::orderBy('name')->pluck('name')->all())->toBe(['A Main', 'B Main']);
});
