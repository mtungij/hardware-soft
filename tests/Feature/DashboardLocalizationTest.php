<?php

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('staff dashboard renders complete English sales labels', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['staff_locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Retail Sales Today')
        ->assertSeeText('Wholesale Sales Today')
        ->assertSeeText('Retail Sales This Month')
        ->assertSeeText('Wholesale Sales This Month')
        ->assertSeeText("Today's Profit")
        ->assertSeeText('Monthly Profit')
        ->assertSeeText('View Details')
        ->assertSeeText('Open POS');
});

test('staff dashboard and navigation render Kiswahili and preserve stored names and acronyms', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $admin->update(['name' => 'Asha Mwakalinga']);

    Branch::whereKey($admin->branch_id)->update(['name' => 'Tawi la Kariakoo']);
    Setting::query()->first()?->update(['company_name' => 'Mwangaza Hardware']);

    $response = $this->actingAs($admin)
        ->withSession(['staff_locale' => 'sw'])
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSeeText('Mauzo ya Rejareja Leo')
        ->assertSeeText('Mauzo ya Jumla Leo')
        ->assertSeeText('Mauzo ya Rejareja Mwezi Huu')
        ->assertSeeText('Mauzo ya Jumla Mwezi Huu')
        ->assertSeeText('Faida ya Leo')
        ->assertSeeText('Faida ya Mwezi')
        ->assertSeeText('Angalia Maelezo')
        ->assertSeeText('Mauzo ya rejareja ya leo')
        ->assertSeeText('Mauzo ya jumla ya leo')
        ->assertSeeText('Faida ya mauzo ya mwezi huu')
        ->assertSeeText('Matawi yote')
        ->assertSeeText('Kuanzia')
        ->assertSeeText('Mpaka')
        ->assertSeeText('Mwelekeo wa Mauzo')
        ->assertSeeText('Mapato dhidi ya Matumizi')
        ->assertSeeText('Mgawanyo wa Stoo')
        ->assertSeeText('Tahadhari za Stoo Ndogo')
        ->assertSeeText('Miamala ya Karibuni')
        ->assertSeeText('Bidhaa na Stoo')
        ->assertSeeText('Manunuzi')
        ->assertSeeText('Mauzo')
        ->assertSeeText('Hesabu')
        ->assertSeeText('Ripoti')
        ->assertSeeText('Usimamizi')
        ->assertSeeText('Asha Mwakalinga')
        ->assertSeeText('Tawi la Kariakoo')
        ->assertSeeText('Mwangaza Hardware')
        ->assertSeeText('TZS')
        ->assertSeeText('POS')
        ->assertDontSeeText('Retail Sales Today')
        ->assertDontSeeText('Wholesale Sales Today')
        ->assertDontSeeText("Today's Profit")
        ->assertDontSeeText('Monthly Profit')
        ->assertDontSeeText('View Details')
        ->assertDontSeeText('Sales Trend')
        ->assertDontSeeText('Revenue vs Expenses')
        ->assertDontSeeText('Stock Distribution')
        ->assertDontSeeText('Recent Transactions');

    $this->withSession(['staff_locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Retail Sales Today')
        ->assertSeeText('Wholesale Sales Today')
        ->assertSeeText("Today's Profit")
        ->assertSeeText('Monthly Profit')
        ->assertSeeText('View Details');
});
