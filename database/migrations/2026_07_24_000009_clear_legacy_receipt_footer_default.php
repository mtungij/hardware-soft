<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('receipt_footer_message', 'Thank you for shopping with Hardex POS.')
            ->update(['receipt_footer_message' => null]);
    }

    public function down(): void
    {
        // Custom company footer messages must not be replaced during rollback.
    }
};
