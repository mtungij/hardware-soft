<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
    || config('database.connections.mysql.database') !== 'hardex_concurrency_validation_v2'
    || ! str_starts_with(config('database.connections.mysql.unix_socket'), '/tmp/hardex-mysql-validation/')) {
    throw new RuntimeException('Requires the disposable concurrency database on the private test socket.');
}
if (($argv[1] ?? '') === 'worker') {
    try {
        $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        $index = (int) $argv[3];
        $actor = User::findOrFail($data['actor']);
        auth()->login($actor);
        touch($argv[2].'.ready-'.$index);
        while (! file_exists($argv[2].'.go')) {
            usleep(10000);
        }
        $input = ['branch_id' => $actor->branch_id, 'amount' => $data['amount'], 'payment_method' => 'cash', 'payment_date' => today(),
            'payment_submission_key' => $data['keys'][$index]];
        $service = app(AccountingService::class);
        $payment = $data['kind'] === 'supplier'
            ? $service->paySupplier(Supplier::findOrFail($data['subjects'][$index]), $input, $actor->id)
            : $service->receiveCustomerPayment(Customer::findOrFail($data['subjects'][$index]), $input, $actor->id);
        echo json_encode(['id' => $payment->id]);
        exit(0);
    } catch (ValidationException $exception) {
        echo json_encode(['validation' => $exception->errors()]);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
        exit(1);
    }
}
try {
    $company = Company::create(['company_name' => 'Concurrency validation '.str()->uuid(), 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $branch = Branch::create(['company_id' => $company->id, 'code' => 'RACE', 'name' => 'Concurrency', 'status' => 'active']);
    $role = Role::create(['company_id' => $company->id, 'name' => 'Super Admin', 'guard_name' => 'web']);
    $actor = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'active']);
    $actor->assignRole($role);
    auth()->login($actor);
    $customer = function () use ($company, $branch, $actor): Customer {
        $customer = Customer::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Race customer', 'phone' => '255700001111', 'customer_type' => 'credit', 'status' => 'active', 'balance_amount' => 100]);
        Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'sale_number' => 'RACE-'.str()->uuid(), 'sale_date' => today(), 'subtotal' => 100, 'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100, 'payment_status' => 'unpaid', 'status' => 'completed', 'created_by' => $actor->id]);

        return $customer;
    };
    $supplier = Supplier::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Race supplier', 'phone' => '255700002222', 'status' => 'active']);
    $purchase = Purchase::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'purchase_date' => today(), 'reference_number' => 'RACE-PO-'.str()->uuid(), 'status' => 'ordered', 'payment_status' => 'unpaid', 'total_amount' => 100, 'balance_amount' => 100, 'created_by' => $actor->id]);
    $checks = 0;
    $check = function (bool $condition, string $message) use (&$checks): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
        $checks++;
        echo "PASS: $message\n";
    };
    $race = function (string $kind, array $subjects, array $keys, int $amount) use ($actor): array {
        $manifest = tempnam('/tmp/hardex-mysql-validation', 'race-');
        file_put_contents($manifest, json_encode(['actor' => $actor->id, 'kind' => $kind, 'subjects' => $subjects, 'keys' => $keys, 'amount' => $amount]));
        $jobs = [];
        foreach ($subjects as $index => $subject) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $manifest, (string) $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $jobs[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 30;
        while (count(glob($manifest.'.ready-*')) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not become ready.');
            }
            usleep(10000);
        }
        touch($manifest.'.go');
        $results = [];
        foreach ($jobs as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0) {
                throw new RuntimeException("Concurrent worker failed ($status): $error $output");
            }
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    };
    $one = $customer();
    $key = (string) str()->uuid();
    $results = $race('customer', array_fill(0, 4, $one->id), array_fill(0, 4, $key), 60);
    $check(count(array_unique(array_column($results, 'id'))) === 1 && CustomerPayment::where('customer_id', $one->id)->count() === 1, 'four simultaneous customer submissions create one payment');
    $check((float) $one->sales()->first()->balance_amount === 40.0 && (float) $one->fresh()->balance_amount === 40.0, 'simultaneous retries allocate and reduce balances once');
    $two = $customer();
    $results = $race('customer', array_fill(0, 4, $two->id), array_map(fn () => (string) str()->uuid(), range(1, 4)), 60);
    $check(count(array_filter($results, fn ($result) => isset($result['id']))) === 1 && count(array_filter($results, fn ($result) => isset($result['validation']))) === 3, 'distinct simultaneous payments cannot overpay customer debt');
    $key = (string) str()->uuid();
    $results = $race('supplier', array_fill(0, 4, $supplier->id), array_fill(0, 4, $key), 60);
    $check(count(array_unique(array_column($results, 'id'))) === 1 && SupplierPayment::where('supplier_id', $supplier->id)->count() === 1 && (float) $purchase->fresh()->balance_amount === 40.0, 'four simultaneous supplier retries create and allocate once');
    $subjects = array_map(fn () => $customer()->id, range(1, 6));
    $results = $race('customer', $subjects, array_map(fn () => (string) str()->uuid(), $subjects), 25);
    $check(count(array_column($results, 'id')) === 6 && CustomerPayment::whereIn('customer_id', $subjects)->distinct()->count('receipt_number') === 6, 'different customers receive unique receipt numbers concurrently');
    $check((float) CustomerPayment::sum('amount') === 270.0 && (float) SupplierPayment::sum('amount') === 60.0, 'concurrent receipts and supplier totals reconcile exactly');
    echo json_encode(['passed' => $checks, 'failed' => 0, 'workers' => 18])."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
