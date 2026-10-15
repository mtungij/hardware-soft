<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('customer_portal_show_prices')->default(false);
        });
        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->timestamp('temporary_pin_expires_at')->nullable();
            $table->timestamp('temporary_pin_used_at')->nullable();
            $table->unsignedTinyInteger('temporary_pin_attempts')->default(0);
        });
        Schema::table('customer_purchase_requests', function (Blueprint $table) {
            $table->string('request_type', 20)->default('rfq');
            $table->boolean('delivery_requested')->default(false);
            $table->text('delivery_address')->nullable();
            $table->text('delivery_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_purchase_requests', fn (Blueprint $table) => $table->dropColumn(['request_type', 'delivery_requested', 'delivery_address', 'delivery_instructions']));
        Schema::table('customer_accounts', fn (Blueprint $table) => $table->dropColumn(['temporary_pin_expires_at', 'temporary_pin_used_at', 'temporary_pin_attempts']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('customer_portal_show_prices'));
    }
};
