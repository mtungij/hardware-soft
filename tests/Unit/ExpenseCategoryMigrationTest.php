<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    $this->originalConnection = config('database.default');
    $connection = config('database.connections.'.$this->originalConnection);
    $connection['prefix'] = 'ec_'.bin2hex(random_bytes(5)).'_';
    if ($connection['driver'] === 'sqlite') {
        $connection['database'] = ':memory:';
    } else {
        // The test bootstrap permits only our private disposable MySQL database.
        expect($connection['unix_socket'])->toStartWith('/tmp/hardex-mysql-validation/');
        expect($connection['database'])->toStartWith('hardex_')->toEndWith('_validation');
    }
    config(['database.connections.category_migration' => $connection, 'database.default' => 'category_migration']);
    DB::purge('category_migration');
    Schema::create('expense_categories', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });
    Schema::create('expenses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
        $table->decimal('amount', 15, 2);
        $table->text('notes');
    });
    DB::table('expense_categories')->insert(['id' => 85, 'company_id' => 28, 'name' => 'Transport', 'description' => 'Historical category', 'status' => 'inactive']);
    DB::table('expenses')->insert(['id' => 120, 'expense_category_id' => 85, 'amount' => '1250.50', 'notes' => 'Historical expense']);
    $this->categorySnapshot = DB::table('expense_categories')->get()->toJson();
    $this->expenseSnapshot = DB::table('expenses')->get()->toJson();
    $this->migration = require database_path('migrations/2026_10_29_000001_scope_expense_category_names_to_company.php');
});

afterEach(function () {
    config(['database.default' => $this->originalConnection]);
    DB::purge('category_migration');
});

test('forward migration preserves historical rows with varied existing indexes and can be retried', function (string $indexState) {
    if ($indexState === 'global') {
        Schema::table('expense_categories', fn (Blueprint $table) => $table->unique('name', 'custom_legacy_name_unique'));
    }
    if ($indexState === 'composite') {
        Schema::table('expense_categories', fn (Blueprint $table) => $table->unique(['company_id', 'name'], 'existing_company_name_unique'));
    }
    $this->migration->up();
    $this->migration->up();
    expect(DB::table('expense_categories')->get()->toJson())->toBe($this->categorySnapshot)
        ->and(DB::table('expenses')->get()->toJson())->toBe($this->expenseSnapshot);
    $indexes = collect(Schema::getIndexes('expense_categories'));
    expect($indexes->filter(fn ($index) => $index['unique'] && $index['columns'] === ['company_id', 'name']))->toHaveCount(1)
        ->and($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['name']))->toBeFalse();
    DB::table('expense_categories')->insert(['company_id' => 29, 'name' => 'Transport']);
    expect(fn () => DB::table('expense_categories')->insert(['company_id' => 28, 'name' => 'Transport']))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['global', 'composite', 'no unique']);

test('migration refuses tenant duplicates without deleting or rewriting any record', function () {
    DB::table('expense_categories')->insert(['company_id' => 28, 'name' => 'Transport']);
    $before = DB::table('expense_categories')->get()->toJson();
    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'manual review');
    expect(DB::table('expense_categories')->get()->toJson())->toBe($before)
        ->and(DB::table('expenses')->get()->toJson())->toBe($this->expenseSnapshot);
});

test('concurrent MySQL inserts produce one category and one duplicate rejection', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL for concurrent database writes.');
    }
    $this->migration->up();
    $connection = config('database.connections.category_migration');
    $worker = <<<'CODE'
$input = json_decode(stream_get_contents(STDIN), true);
$c = $input['connection'];
$pdo = new PDO('mysql:unix_socket='.$c['unix_socket'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
while (microtime(true) < $input['start']) { usleep(1000); }
try {
    $pdo->exec('INSERT INTO `'.$c['prefix'].'expense_categories` (company_id, name) VALUES (28, "Concurrent Transport")');
    echo 'created';
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) !== 1062) { throw $e; }
    echo 'duplicate';
}
CODE;
    $input = json_encode(['connection' => $connection, 'start' => microtime(true) + 0.5]);
    $workers = [];
    foreach ([1, 2] as $number) {
        $process = new Process([PHP_BINARY, '-r', $worker]);
        $process->setInput($input);
        $process->start();
        $workers[] = $process;
    }
    $results = [];
    foreach ($workers as $process) {
        expect($process->wait())->toBe(0, $process->getErrorOutput());
        $results[] = $process->getOutput();
    }
    sort($results);
    expect($results)->toBe(['created', 'duplicate'])
        ->and(DB::table('expense_categories')->where('name', 'Concurrent Transport')->count())->toBe(1);
});
