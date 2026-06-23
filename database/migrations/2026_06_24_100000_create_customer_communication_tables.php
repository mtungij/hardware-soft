<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('image')->nullable();
            $table->string('attachment')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->string('visibility_type')->default('all_customers');
            $table->dateTime('publish_date')->nullable();
            $table->dateTime('expiry_date')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->json('target_filters')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'publish_date', 'expiry_date']);
            $table->index('visibility_type');
        });

        Schema::create('announcement_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_delivered')->default(true);
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'customer_id']);
            $table->index(['customer_id', 'is_read']);
        });

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->string('attachment')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['draft', 'sent'])->default('draft');
            $table->json('channels')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status', 'read_at']);
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->text('message');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_notifications', 'priority')) {
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->after('message');
            }

            if (! Schema::hasColumn('customer_notifications', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('read_at');
            }

            if (! Schema::hasColumn('customer_notifications', 'channels')) {
                $table->json('channels')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('customer_notifications', 'channels')) {
                $table->dropColumn('channels');
            }

            if (Schema::hasColumn('customer_notifications', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('customer_notifications', 'priority')) {
                $table->dropColumn('priority');
            }
        });

        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('announcement_customers');
        Schema::dropIfExists('announcements');
    }
};
