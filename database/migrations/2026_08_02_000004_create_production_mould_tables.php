<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_moulds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pm_company_fk')->cascadeOnDelete();
            $table->foreignId('product_family_id')->constrained('product_families', indexName: 'pm_family_fk')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->decimal('expected_output_per_cycle', 24, 12)->nullable();
            $table->decimal('expected_output_per_day', 24, 12)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('under_maintenance')->default(false);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pm_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'pm_updated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'pm_company_code_uq');
            $table->index(['company_id', 'active', 'under_maintenance'], 'mould_availability_ix');
            $table->index(['company_id', 'product_family_id'], 'mould_family_ix');
        });

        Schema::create('production_machine_mould', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pmm_company_fk')->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('machines', indexName: 'pmm_machine_fk')->cascadeOnDelete();
            $table->foreignId('production_mould_id')->constrained('production_moulds', indexName: 'pmm_mould_fk')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['machine_id', 'production_mould_id'], 'machine_mould_compatibility_uq');
            $table->index(['company_id', 'machine_id'], 'machine_mould_company_ix');
        });

        Schema::create('production_mould_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'pmi_company_fk')->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('machines', indexName: 'pmi_machine_fk')->restrictOnDelete();
            $table->foreignId('production_mould_id')->constrained('production_moulds', indexName: 'pmi_mould_fk')->restrictOnDelete();
            $table->foreignId('current_machine_id')->nullable()->constrained('machines', indexName: 'pmi_current_machine_fk')->restrictOnDelete();
            $table->foreignId('current_mould_id')->nullable()->constrained('production_moulds', indexName: 'pmi_current_mould_fk')->restrictOnDelete();
            $table->timestamp('installed_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 40)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users', indexName: 'pmi_installed_by_fk')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users', indexName: 'pmi_removed_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique('current_machine_id', 'pmi_current_machine_uq');
            $table->unique('current_mould_id', 'pmi_current_mould_uq');
            $table->index(['company_id', 'machine_id', 'installed_at'], 'mould_install_machine_history_ix');
            $table->index(['company_id', 'production_mould_id', 'installed_at'], 'mould_install_mould_history_ix');
        });

        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->foreignId('production_mould_id')->nullable()->after('machine_id')
                ->constrained('production_moulds', indexName: 'pma_mould_fk')->restrictOnDelete();
            $table->foreignId('production_mould_installation_id')->nullable()->after('production_mould_id')
                ->constrained('production_mould_installations', indexName: 'pma_mould_install_fk')->restrictOnDelete();
            $table->foreignId('production_recipe_id')->nullable()->after('product_id')
                ->constrained('production_recipes', indexName: 'pma_recipe_fk')->restrictOnDelete();
        });

        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $familyId = DB::table('product_families')->where('company_id', $companyId)
                ->where('code', 'concrete-blocks')->value('id');
            if (! $familyId) {
                continue;
            }

            $machineIds = DB::table('machines')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id');
            if ($machineIds->isEmpty()) {
                DB::table('production_moulds')->insert([
                    'company_id' => $companyId, 'product_family_id' => $familyId,
                    'code' => 'DEFAULT-CONCRETE-BLOCKS', 'name' => 'Default Concrete Blocks Mould',
                    'active' => true, 'under_maintenance' => false,
                    'description' => 'Initial concrete-block mould. Configure machine compatibility before installation.',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            foreach ($machineIds as $machineId) {
                $mouldId = DB::table('production_moulds')->insertGetId([
                    'company_id' => $companyId,
                    'product_family_id' => $familyId,
                    'code' => 'DEFAULT-CONCRETE-BLOCKS-'.$machineId,
                    'name' => 'Default Concrete Blocks Mould '.$machineId,
                    'active' => true,
                    'under_maintenance' => false,
                    'description' => 'Initial compatibility mould created for an existing concrete-block machine.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('production_machine_mould')->insert([
                    'company_id' => $companyId,
                    'machine_id' => $machineId,
                    'production_mould_id' => $mouldId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $installationId = DB::table('production_mould_installations')->insertGetId([
                    'company_id' => $companyId,
                    'machine_id' => $machineId,
                    'production_mould_id' => $mouldId,
                    'current_machine_id' => $machineId,
                    'current_mould_id' => $mouldId,
                    'installed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach (DB::table('production_machine_assignments')->where('company_id', $companyId)->where('machine_id', $machineId)->get() as $assignment) {
                    $recipeId = DB::table('production_recipes')->where('company_id', $companyId)
                        ->where('product_id', $assignment->product_id)->where('status', 'active')->value('id');
                    DB::table('production_machine_assignments')->where('id', $assignment->id)->update([
                        'production_mould_id' => $mouldId,
                        'production_mould_installation_id' => $installationId,
                        'production_recipe_id' => $recipeId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('production_machine_assignments', function (Blueprint $table): void {
            $table->dropForeign('pma_recipe_fk');
            $table->dropForeign('pma_mould_install_fk');
            $table->dropForeign('pma_mould_fk');
            $table->dropColumn(['production_recipe_id', 'production_mould_installation_id', 'production_mould_id']);
        });
        Schema::dropIfExists('production_mould_installations');
        Schema::dropIfExists('production_machine_mould');
        Schema::dropIfExists('production_moulds');
    }
};
