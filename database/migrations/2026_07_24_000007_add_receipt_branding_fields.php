<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('tagline')->nullable()->after('business_type');
            $table->string('alternate_phone')->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
            $table->boolean('show_tax_identifiers_on_receipt')->default(false)->after('vrn_number');
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->string('company_tagline')->nullable()->after('business_type');
            $table->string('alternate_phone')->nullable()->after('company_phone');
            $table->string('company_website')->nullable()->after('company_email');
            $table->boolean('show_tax_identifiers_on_receipt')->default(false)->after('vrn_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'tagline',
                'alternate_phone',
                'website',
                'show_tax_identifiers_on_receipt',
            ]);
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'company_tagline',
                'alternate_phone',
                'company_website',
                'show_tax_identifiers_on_receipt',
            ]);
        });
    }
};
