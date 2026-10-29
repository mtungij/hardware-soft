<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Optional PHPUnit bootstrap: additive migrations only, on explicitly disposable databases.
require __DIR__.'/../vendor/autoload.php';
$application = require __DIR__.'/../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$driver = DB::getDriverName();
$safeSqlite = $driver === 'sqlite' && config('database.connections.sqlite.database') === ':memory:';
$safeMysql = $driver === 'mysql'
    && str_starts_with(config('database.connections.mysql.database'), 'hardex_')
    && str_ends_with(config('database.connections.mysql.database'), '_validation')
    && str_starts_with(config('database.connections.mysql.unix_socket'), '/tmp/hardex-mysql-validation/');
if (! app()->environment('testing') || (! $safeSqlite && ! $safeMysql)) {
    throw new RuntimeException('Additive test bootstrap requires an in-memory SQLite database or the private disposable MySQL server.');
}
Artisan::call('migrate', ['--force' => true]);
RefreshDatabaseState::$migrated = true;
if ($safeSqlite) {
    RefreshDatabaseState::$inMemoryConnections['sqlite'] = DB::connection()->getPdo();
}
// Return to PHPUnit's original handlers before it snapshots test state.
restore_error_handler();
restore_exception_handler();
