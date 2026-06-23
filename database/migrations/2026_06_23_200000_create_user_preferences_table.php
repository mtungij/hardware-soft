<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('guard')->default('web');
            $table->unsignedBigInteger('user_id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['guard', 'user_id', 'key']);
            $table->index(['guard', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
