<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 80);
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('colour', 30)->default('cyan');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('default_curing_days')->nullable();
            $table->unsignedSmallInteger('default_earliest_release_days')->nullable();
            $table->boolean('default_requires_curing')->default(false);
            $table->boolean('default_requires_qc')->default(false);
            $table->foreignId('default_selling_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('default_inventory_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_family_id')->nullable()->after('inventory_source')
                ->constrained('product_families')->restrictOnDelete();
        });

        $definitions = [
            ['Concrete Blocks', 'concrete-blocks', 'cube', 'cyan'],
            ['Hollow Blocks', 'hollow-blocks', 'grid', 'sky'],
            ['Solid Blocks', 'solid-blocks', 'square', 'slate'],
            ['Paving Blocks', 'paving-blocks', 'pattern', 'amber'],
            ['Kerbstones', 'kerbstones', 'curb', 'orange'],
            ['Concrete Pipes', 'concrete-pipes', 'circle', 'blue'],
            ['Culverts', 'culverts', 'tunnel', 'indigo'],
            ['Cover Slabs', 'cover-slabs', 'layers', 'violet'],
            ['Channels', 'channels', 'channel', 'teal'],
            ['Other Concrete Products', 'other-concrete-products', 'shapes', 'slate'],
        ];
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($definitions as [$name, $code, $icon, $colour]) {
                DB::table('product_families')->insert([
                    'company_id' => $companyId,
                    'name' => $name,
                    'code' => $code,
                    'icon' => $icon,
                    'colour' => $colour,
                    'active' => true,
                    'default_requires_curing' => false,
                    'default_requires_qc' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $concreteBlocksId = DB::table('product_families')
                ->where('company_id', $companyId)
                ->where('code', 'concrete-blocks')
                ->value('id');

            DB::table('products')
                ->where('company_id', $companyId)
                ->where('inventory_source', 'manufactured')
                ->whereNull('product_family_id')
                ->update(['product_family_id' => $concreteBlocksId]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_family_id');
        });

        Schema::dropIfExists('product_families');
    }
};
