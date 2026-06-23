<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guard')->default('web');
            $table->string('tour_name');
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('skipped')->default(false);
            $table->unsignedInteger('last_step')->default(0);
            $table->json('checklist')->nullable();
            $table->timestamps();

            $table->unique(['guard', 'user_id', 'customer_account_id', 'tour_name'], 'onboarding_progress_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_onboarding_progress');
    }
};
