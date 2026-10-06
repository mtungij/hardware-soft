<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receiving_note_items', function (Blueprint $table) {
            $table->decimal('markup_percentage', 8, 2)->nullable();
            $table->decimal('suggested_selling_price', 18, 2)->nullable();
            $table->string('suggested_selling_unit_code_snapshot')->nullable();
            $table->decimal('suggested_selling_conversion_factor', 18, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receiving_note_items', function (Blueprint $table) {
            $table->dropColumn(['markup_percentage', 'suggested_selling_price', 'suggested_selling_unit_code_snapshot', 'suggested_selling_conversion_factor']);
        });
    }
};
