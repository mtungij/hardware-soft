<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('district')->nullable()->after('region');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('district')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('district');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('district');
        });
    }
};
