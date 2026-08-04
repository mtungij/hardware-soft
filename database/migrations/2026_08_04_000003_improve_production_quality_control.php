<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'production.record_qc_result',
        'production.approve_qc',
        'production.manage_qc_plans',
        'production.override_qc_separation',
    ];

    public function up(): void
    {
        Schema::table('production_quality_plans', function (Blueprint $table): void {
            $table->boolean('enforce_approval_separation')->default(false)->after('requires_approval');
            $table->boolean('requires_failure_evidence')->default(false)->after('enforce_approval_separation');
        });

        Schema::table('production_quality_inspections', function (Blueprint $table): void {
            $table->string('plan_name_snapshot')->nullable()->after('production_quality_plan_id');
            $table->string('plan_version_snapshot', 50)->nullable()->after('plan_name_snapshot');
            $table->text('reason_justification')->nullable()->after('rejection_reason');
            $table->string('disposition', 30)->nullable()->after('reason_justification');
            $table->date('retest_date')->nullable()->after('retest_required');
            $table->timestamp('qc_rejection_applied_at')->nullable()->after('approved_by');
        });

        Schema::table('production_quality_inspection_results', function (Blueprint $table): void {
            $table->text('requirement_snapshot')->nullable()->after('check_name');
            $table->string('unit_snapshot', 50)->nullable()->after('unit_id');
            $table->string('plan_version_snapshot', 50)->nullable()->after('unit_snapshot');
        });

        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->decimal('qc_rejected_quantity', 24, 12)->default(0)->after('damaged_quantity');
            $table->decimal('release_eligible_quantity', 24, 12)->default(0)->after('qc_rejected_quantity');
        });

        Schema::create('production_quality_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_quality_inspection_id')->constrained('production_quality_inspections')->restrictOnDelete();
            $table->string('category', 30);
            $table->string('original_name');
            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->index(['company_id', 'production_quality_inspection_id'], 'qc_attachment_inspection_ix');
        });

        Schema::create('production_quality_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_quality_inspection_id')->constrained('production_quality_inspections')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('reference_number', 80);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['production_quality_inspection_id', 'occurred_at'], 'qc_audit_timeline_ix');
        });

        DB::table('production_quality_inspections')->orderBy('id')->each(function (object $inspection): void {
            $plan = $inspection->production_quality_plan_id
                ? DB::table('production_quality_plans')->where('id', $inspection->production_quality_plan_id)->first()
                : null;
            DB::table('production_quality_inspections')->where('id', $inspection->id)->update([
                'plan_name_snapshot' => $plan?->name,
                'plan_version_snapshot' => $plan?->version,
            ]);
        });
        DB::table('production_curing_batches')->orderBy('id')->each(function (object $batch): void {
            $approved = DB::table('production_quality_inspections')
                ->where('production_curing_batch_id', $batch->id)
                ->where('approval_status', 'approved')
                ->whereIn('result', ['passed', 'conditional'])
                ->latest('approved_at')->first();
            if ($approved) {
                $eligible = bcsub((string) ($approved->passed_quantity ?? 0), (string) $batch->released_quantity, 12);
                DB::table('production_curing_batches')->where('id', $batch->id)->update([
                    'release_eligible_quantity' => bccomp($eligible, '0', 12) < 0 ? 0 : $eligible,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        Role::query()->where('name', 'Quality Manager')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['production.record_qc_result', 'production.approve_qc', 'production.manage_qc_plans']));
        Role::query()->where('name', 'Quality Inspector')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo('production.record_qc_result'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('production_quality_audit_events');
        Schema::dropIfExists('production_quality_attachments');

        Schema::table('production_curing_batches', function (Blueprint $table): void {
            $table->dropColumn(['qc_rejected_quantity', 'release_eligible_quantity']);
        });
        Schema::table('production_quality_inspection_results', function (Blueprint $table): void {
            $table->dropColumn(['requirement_snapshot', 'unit_snapshot', 'plan_version_snapshot']);
        });
        Schema::table('production_quality_inspections', function (Blueprint $table): void {
            $table->dropColumn(['plan_name_snapshot', 'plan_version_snapshot', 'reason_justification', 'disposition', 'retest_date', 'qc_rejection_applied_at']);
        });
        Schema::table('production_quality_plans', function (Blueprint $table): void {
            $table->dropColumn(['enforce_approval_separation', 'requires_failure_evidence']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
