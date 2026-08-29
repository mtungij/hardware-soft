<?php

use App\Support\WhatsAppPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('login_phone', 20)->nullable()->after('phone');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->unsignedInteger('credential_version')->default(0)->after('must_change_password');
            $table->string('last_credential_operation_key', 100)->nullable()->after('credential_version');
            $table->unsignedBigInteger('last_credentials_notification_id')->nullable()->after('last_credential_operation_key');
            $table->timestamp('last_credentials_sent_at')->nullable()->after('last_credentials_notification_id');
            $table->unique('login_phone', 'ca_login_phone_uq');
        });

        Schema::table('whatsapp_notifications', function (Blueprint $table): void {
            $table->longText('encrypted_message')->nullable()->after('message');
        });

        Schema::create('customer_portal_security_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('customer_account_id')->nullable();
            $table->string('event', 80);
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'created_at'], 'cpse_customer_ix');
            $table->index(['customer_account_id', 'event'], 'cpse_account_event_ix');
            $table->foreign('company_id', 'cpse_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_id', 'cpse_customer_fk')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('customer_account_id', 'cpse_account_fk')->references('id')->on('customer_accounts')->nullOnDelete();
        });

        $accounts = DB::table('customer_accounts')->select(['id', 'customer_id', 'phone'])->orderBy('id')->get();
        $normalized = [];

        foreach ($accounts as $account) {
            try {
                $phone = WhatsAppPhone::normalize($account->phone);
                $normalized[$phone][] = $account;
            } catch (Throwable) {
                // Invalid legacy numbers remain disabled for phone login until staff resolves them.
            }
        }

        foreach ($normalized as $phone => $matches) {
            if (count($matches) !== 1) {
                continue;
            }

            $account = $matches[0];
            DB::table('customer_accounts')->where('id', $account->id)->update([
                'phone' => $phone,
                'login_phone' => $phone,
            ]);
            DB::table('customers')->where('id', $account->customer_id)->update(['phone' => $phone]);
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            ['name' => 'customers.manage_portal_access', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $permissionId = DB::table('permissions')->where('name', 'customers.manage_portal_access')->where('guard_name', 'web')->value('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'Admin', 'Manager'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_security_events');

        Schema::table('whatsapp_notifications', function (Blueprint $table): void {
            $table->dropColumn('encrypted_message');
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropUnique('ca_login_phone_uq');
            $table->dropColumn([
                'login_phone', 'must_change_password', 'credential_version', 'last_credential_operation_key',
                'last_credentials_notification_id', 'last_credentials_sent_at',
            ]);
        });
    }
};
