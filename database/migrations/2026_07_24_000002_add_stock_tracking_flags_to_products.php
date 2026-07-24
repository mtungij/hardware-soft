<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'tracks_batch')) {
                $table->boolean('tracks_batch')->default(false)->after('quantity_step');
            }

            if (! Schema::hasColumn('products', 'tracks_expiry')) {
                $table->boolean('tracks_expiry')->default(false)->after('tracks_batch');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            foreach (['tracks_expiry', 'tracks_batch'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
