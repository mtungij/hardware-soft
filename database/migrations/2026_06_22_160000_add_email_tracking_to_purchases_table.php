<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('received_at');
            $table->foreignId('email_sent_by')->nullable()->after('email_sent_at')->constrained('users')->nullOnDelete();
            $table->enum('email_status', ['pending', 'sent', 'failed'])->nullable()->after('email_sent_by');
            $table->string('email_recipient')->nullable()->after('email_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_sent_by');
            $table->dropColumn(['email_sent_at', 'email_status', 'email_recipient']);
        });
    }
};
