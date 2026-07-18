<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_sizes')) {
            Schema::create('product_sizes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('symbol');
                $table->text('description')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->unique(['company_id', 'symbol'], 'product_sizes_company_symbol_unique');
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'product_size_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('product_size_id')
                    ->nullable()
                    ->after('unit_id')
                    ->constrained('product_sizes', indexName: 'products_size_id_foreign')
                    ->nullOnDelete();
            });
        }

        $this->seedCommonSizes();
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_size_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropForeign('products_size_id_foreign');
                $table->dropColumn('product_size_id');
            });
        }

        Schema::dropIfExists('product_sizes');
    }

    private function seedCommonSizes(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        $sizes = [
            ['½ × ½', '½ × ½', 'Half inch by half inch'],
            ['1 × 1', '1 × 1', 'One inch by one inch'],
            ['1¼ × 1¼', '1¼ × 1¼', 'One and quarter inch by one and quarter inch'],
            ['1½ × 1½', '1½ × 1½', 'One and half inch by one and half inch'],
            ['2 × 2', '2 × 2', 'Two inch by two inch'],
            ['2 × 3', '2 × 3', 'Two inch by three inch'],
            ['2 × 4 (2mm)', '2 × 4 (2mm)', 'Two inch by four inch, 2mm thickness'],
            ['2 × 4 (3mm)', '2 × 4 (3mm)', 'Two inch by four inch, 3mm thickness'],
            ['1½ × 2½', '1½ × 2½', 'One and half inch by two and half inch'],
            ['3 × 3', '3 × 3', 'Three inch by three inch'],
            ['4 × 4 (3mm)', '4 × 4 (3mm)', 'Four inch by four inch, 3mm thickness'],
            ['6 × 6 (3mm)', '6 × 6 (3mm)', 'Six inch by six inch, 3mm thickness'],
        ];

        $companyIds = DB::table('companies')
            ->where('business_type', 'Hardware Store')
            ->pluck('id');

        foreach ($companyIds as $companyId) {
            foreach ($sizes as [$name, $symbol, $description]) {
                DB::table('product_sizes')->updateOrInsert(
                    ['company_id' => $companyId, 'symbol' => $symbol],
                    [
                        'name' => $name,
                        'description' => $description,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
