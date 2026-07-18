<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'allow_fractional_sales')) {
                $table->boolean('allow_fractional_sales')->default(false)->after('status');
            }
        });

        DB::table('categories')
            ->whereIn('code', ['PIP'])
            ->update(['allow_fractional_sales' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'allow_fractional_sales')) {
                $table->dropColumn('allow_fractional_sales');
            }
        });
    }
};
