<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RecoverRoleAdministration extends Command
{
    protected $signature = 'roles:recover-admin {company : Company ID} {user : Existing Super Admin user ID} {--force : Explicitly grant role-management recovery access}';

    protected $description = 'Recover roles.manage for an existing company Super Admin without changing business permissions or role assignments';

    public function handle(): int
    {
        if (! $this->laravel->runningInConsole() || ! $this->option('force')) {
            $this->error('No changes made. Review the company and user IDs, then pass --force explicitly.');

            return self::FAILURE;
        }
        $user = User::withoutGlobalScopes()->where('company_id', $this->argument('company'))->find($this->argument('user'));
        $eligible = $user && $user->roles()->where('name', 'Super Admin')->where('guard_name', 'web')
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $user->company_id))->exists();
        if (! $eligible || ! $user->isActive()) {
            $this->error('Choose an active, existing Super Admin belonging to this company. No changes made.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user) {
            $user->givePermissionTo(Permission::findOrCreate('roles.manage', 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::notice('Role administration recovered through console', ['company_id' => $user->company_id, 'user_id' => $user->id]);
        $this->info('Granted only direct roles.manage recovery access. Business permissions and role assignments remain unchanged.');

        return self::SUCCESS;
    }
}
