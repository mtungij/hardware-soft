<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $lastExistingId = (int) DB::table('settings')->max('id');
        Schema::table('settings', function (Blueprint $table) {
            $table->enum('default_receipt_width', ['58', '80'])->default('80');
        });
        // Keep the previous 58mm layout for existing settings; new businesses use 80mm.
        DB::table('settings')->where('id', '<=', $lastExistingId)->update(['default_receipt_width' => '58']);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('default_receipt_width');
        });
    }
};
