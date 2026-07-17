<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || Schema::hasColumn('settings', 'credit_limit_enforcement')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->string('credit_limit_enforcement')->default('block')->after('allow_multiple_dispensing_locations');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'credit_limit_enforcement')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('credit_limit_enforcement');
        });
    }
};
