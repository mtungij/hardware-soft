<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_sale_items', function (Blueprint $table): void {
            $table->string('price_source')->nullable()->after('internal_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('internal_sale_items', function (Blueprint $table): void {
            $table->dropColumn('price_source');
        });
    }
};
