<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('system_initialized')->default(false)->after('theme_color');
            $table->string('business_type')->nullable()->after('company_name');
            $table->string('tin_number')->nullable()->after('business_type');
            $table->string('vrn_number')->nullable()->after('tin_number');
            $table->string('whatsapp_number')->nullable()->after('company_phone');
            $table->string('region')->nullable()->after('company_address');
            $table->string('district')->nullable()->after('region');
            $table->string('country')->default('Tanzania')->after('district');
            $table->text('business_description')->nullable()->after('country');
            $table->string('timezone')->default('Africa/Dar_es_Salaam')->after('currency');
            $table->string('language')->default('sw')->after('timezone');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('district')->nullable()->after('region');
            $table->string('manager_name')->nullable()->after('address');
            $table->boolean('is_default')->default(false)->after('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system_owner')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'system_initialized',
                'business_type',
                'tin_number',
                'vrn_number',
                'whatsapp_number',
                'region',
                'district',
                'country',
                'business_description',
                'timezone',
                'language',
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['district', 'manager_name', 'is_default']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system_owner');
        });
    }
};
