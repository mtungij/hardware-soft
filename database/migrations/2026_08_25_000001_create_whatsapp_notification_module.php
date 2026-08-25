<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_whatsapp_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('enabled')->default(false);
            $table->boolean('sending_paused')->default(false);
            $table->string('device_id')->nullable();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->time('daily_summary_time')->default('20:00');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->json('enabled_categories')->nullable();
            $table->unsignedSmallInteger('minimum_send_interval_seconds')->default(15);
            $table->unsignedSmallInteger('maximum_messages_per_minute')->default(3);
            $table->unsignedSmallInteger('maximum_messages_per_hour')->default(60);
            $table->unsignedSmallInteger('low_stock_cooldown_hours')->default(24);
            $table->string('test_recipient', 30)->nullable();
            $table->string('last_device_state', 30)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique('company_id', 'cws_company_uq');
            $table->foreign('company_id', 'cws_company_fk')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('whatsapp_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('phone', 30);
            $table->enum('scope', ['company', 'branch'])->default('company');
            $table->boolean('active')->default(true);
            $table->json('categories')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone'], 'wr_company_phone_uq');
            $table->index(['company_id', 'active'], 'wr_company_active_ix');
            $table->foreign('company_id', 'wr_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id', 'wr_branch_fk')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('user_id', 'wr_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('key', 80);
            $table->string('category', 80);
            $table->string('name');
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'key'], 'wt_company_key_uq');
            $table->foreign('company_id', 'wt_company_fk')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('whatsapp_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('phone', 30);
            $table->string('notification_type', 80);
            $table->string('channel', 20)->default('whatsapp');
            $table->string('category', 80);
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->enum('attachment_type', ['file', 'image'])->nullable();
            $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'failed', 'cancelled', 'suppressed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('message_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key', 191);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'wn_company_idem_uq');
            $table->index(['company_id', 'status', 'available_at'], 'wn_company_status_ix');
            $table->index(['device_id', 'status', 'id'], 'wn_device_queue_ix');
            $table->index(['company_id', 'category', 'created_at'], 'wn_company_category_ix');
            $table->foreign('company_id', 'wn_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id', 'wn_branch_fk')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('recipient_id', 'wn_recipient_fk')->references('id')->on('whatsapp_recipients')->nullOnDelete();
        });

        $now = now();
        $permissions = collect([
            'whatsapp.view_settings',
            'whatsapp.manage_settings',
            'whatsapp.view_logs',
            'whatsapp.retry_failed',
            'whatsapp.manage_recipients',
            'whatsapp.manage_templates',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('permissions')->insertOrIgnore($permissions);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notifications');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_recipients');
        Schema::dropIfExists('company_whatsapp_settings');

        // Permission rows are retained because deployed roles may reference them.
    }
};
