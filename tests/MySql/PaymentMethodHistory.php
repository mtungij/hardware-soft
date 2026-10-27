<?php

// Run only against a freshly created, disposable database on a private MySQL socket.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
    || $connection['database'] !== 'hardex_history_validation_v2'
    || ! str_starts_with($connection['unix_socket'], '/tmp/hardex-mysql-validation/')) {
    throw new RuntimeException('Requires the isolated historical-validation database and private /tmp socket.');
}
if (DB::select('SHOW TABLES') !== []) {
    throw new RuntimeException('Refusing to change a nonempty database. Create a new disposable database first.');
}
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS: {$message}\n";
};
Schema::create('companies', fn (Blueprint $table) => $table->id());
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained();
});
Schema::create('company_payment_methods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained();
    $table->string('type', 30);
    $table->string('display_name');
});
$tables = ['sale_payments', 'customer_payments', 'supplier_payments', 'expenses', 'customer_receipts', 'customer_deposits', 'customer_material_cash_transactions', 'goods_receipt_additional_costs'];
foreach ($tables as $name) {
    Schema::create($name, function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained();
        $table->foreignId('branch_id')->constrained();
        $table->enum('payment_method', ['cash', 'mobile_money', 'bank', 'credit', 'cash_deposit']);
        $table->decimal('amount', 15, 2);
        $table->string('reference_number');
        $table->timestamps();
    });
}
DB::table('companies')->insert([['id' => 28], ['id' => 29]]);
DB::table('branches')->insert([['id' => 32, 'company_id' => 28], ['id' => 33, 'company_id' => 29]]);
DB::table('company_payment_methods')->insert(['id' => 85, 'company_id' => 28, 'type' => 'bank', 'display_name' => 'CRDB Bank']);
$before = [];
foreach ($tables as $name) {
    foreach ([28 => 32, 29 => 33] as $company => $branch) {
        foreach (['cash' => '100.25', 'mobile_money' => '205.50', 'bank' => '309.75', 'credit' => '400.00', 'cash_deposit' => '50.00'] as $type => $amount) {
            DB::table($name)->insert(['company_id' => $company, 'branch_id' => $branch, 'payment_method' => $type, 'amount' => $amount,
                'reference_number' => "$name-$company-$type", 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00']);
        }
    }
    $before[$name] = DB::table($name)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}
$migration = require database_path('migrations/2026_10_27_000001_centralize_payment_methods.php');
$migration->up();
foreach ($tables as $name) {
    $columns = array_keys($before[$name][0]);
    $after = DB::table($name)->select($columns)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $check($after === $before[$name], "$name: every original column, ID, amount and timestamp preserved");
    $check(DB::table($name.' as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->whereColumn('p.company_id', '!=', 'm.company_id')->count() === 0, "$name: backfill isolated by company");
    $check(DB::table($name)->where('payment_method', 'credit')->whereNull('payment_method_id')->count() === 2, "$name: credit remains an outstanding-balance marker");
    $check(DB::table($name)->where('payment_method', 'mobile_money')->where('payment_method_name_snapshot', 'Mobile Money')->count() === 2
        && DB::table($name)->where('payment_method', 'bank')->where('payment_method_name_snapshot', 'Bank')->count() === 2, "$name: generic historical providers remain honest snapshots");
}
$snapshot = [];
foreach ($tables as $name) {
    $snapshot[$name] = DB::table($name)->orderBy('id')->get()->toJson();
}
$migration->up();
$check(collect($tables)->every(fn ($name) => DB::table($name)->orderBy('id')->get()->toJson() === $snapshot[$name]), 'rerunning the migration leaves every payment unchanged');
$check(DB::table('payment_methods')->count() === 7 && DB::table('payment_methods')->where('id', 85)->value('display_name') === 'CRDB Bank'
    && DB::table('company_payment_methods')->count() === 1, 'legacy catalog IDs and archive preserved; defaults do not duplicate');
DB::table('payment_methods')->where('legacy_code', 'bank')->update(['display_name' => 'Renamed', 'is_active' => false]);
$check(collect($tables)->every(fn ($name) => DB::table($name)->where('payment_method', 'bank')->where('payment_method_name_snapshot', 'Bank')->count() === 2), 'renaming and deactivating methods preserves historical names');
// Emulate a partially applied MySQL DDL run: a column exists but its index does not.
Schema::table('expenses', fn (Blueprint $table) => $table->index('company_id', 'history_company_index'));
Schema::table('expenses', fn (Blueprint $table) => $table->dropUnique('pm_submit_'.substr(sha1('expenses'), 0, 12).'_uq'));
$migration->up();
$indexes = Schema::getIndexes('expenses');
$check(collect($indexes)->contains(fn ($index) => $index['name'] === 'pm_submit_'.substr(sha1('expenses'), 0, 12).'_uq'), 'partial DDL retry restores the idempotency constraint');
$totals = DB::table('sale_payments')->selectRaw('company_id, SUM(CASE WHEN payment_method != ? THEN amount ELSE 0 END) AS money_received, SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) AS credit_marker', ['credit', 'credit'])->groupBy('company_id')->get();
$check($totals->every(fn ($row) => $row->money_received === '665.50' && $row->credit_marker === '400.00'), 'company reconciliation: received 665.50 and credit 400.00 before and after migration');
echo json_encode(['mysql' => DB::selectOne('SELECT VERSION() AS version')->version, 'passed' => $checks, 'failed' => 0, 'historical_records' => count($tables) * 10, 'reconciliation' => $totals], JSON_PRETTY_PRINT)."\n";
