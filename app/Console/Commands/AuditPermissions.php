<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\LegacyPermissionAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

class AuditPermissions extends Command
{
    protected $signature = 'permissions:audit';

    protected $description = 'Read-only comparison of registered web permissions and literal authorization checks in application source';

    public function handle(): int
    {
        $references = [];
        foreach ([app_path(), resource_path('views'), base_path('routes')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if (! str_ends_with($file->getFilename(), '.php') || $file->getRealPath() === __FILE__) {
                    continue;
                }
                $source = $file->getContents();
                $patterns = [
                    '/(?:->|::)(?:can|cannot|hasPermissionTo|hasAnyPermission|hasAllPermissions|canAny)\(\s*([\'"][^\'"]+[\'"]|\[[^\]]+\])/s',
                    '/@can(?:any)?\(\s*([\'"][^\'"]+[\'"]|\[[^\]]+\])/s',
                    '/[\'"]permission[\'"]\s*=>\s*([\'"][^\'"]+[\'"])/s',
                ];
                foreach ($patterns as $pattern) {
                    preg_match_all($pattern, $source, $matches);
                    foreach ($matches[1] as $expression) {
                        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $expression, $names);
                        foreach ($names[1] as $name) {
                            $references[$name][] = str_replace(base_path().'/', '', $file->getRealPath());
                        }
                    }
                }
                preg_match_all('/[\'"]can:([^\'"]+)[\'"]/', $source, $matches);
                foreach ($matches[1] as $name) {
                    $references[explode(',', $name)[0]][] = str_replace(base_path().'/', '', $file->getRealPath());
                }
                preg_match_all('/[\'"]production\.access:([^\'"]+)[\'"]/', $source, $matches);
                foreach ($matches[1] as $names) {
                    foreach (explode(',', $names) as $name) {
                        $references[$name][] = str_replace(base_path().'/', '', $file->getRealPath());
                    }
                }
            }
        }
        foreach (LegacyPermissionAliases::MAP as $canonical => $aliases) {
            foreach ([$canonical, ...$aliases] as $name) {
                $references[$name][] = 'app/Support/LegacyPermissionAliases.php';
            }
        }
        ksort($references);
        $registered = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
        $missing = array_diff(array_keys($references), $registered);
        $administrationAbilities = ['roles.manage', 'dashboard.view', 'settings.manage', 'company-settings.update', 'products.view', 'stock.view', 'purchases.view', 'accounting.view', 'reports.sales', 'sales.view', 'sales.create', 'sales.cancel', 'whatsapp.view_settings', 'whatsapp.view_logs'];
        $this->line(json_encode([
            'registered_count' => count($registered), 'referenced_count' => count($references),
            'registered_permissions' => $registered,
            'required_administration_abilities' => $administrationAbilities,
            'literal_references' => array_map(fn (array $paths) => array_values(array_unique($paths)), $references),
            'missing' => array_intersect_key($references, array_flip($missing)),
            'registered_without_literal_check' => array_values(array_diff($registered, array_keys($references))),
            'legacy_any_grant_rules' => LegacyPermissionAliases::MAP,
            'super_admin_roles' => Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->withCount('permissions')->get()
                ->map(fn (Role $role) => ['id' => $role->id, 'company_id' => $role->company_id, 'permissions_count' => $role->permissions_count,
                    'cancellation_assigned' => $role->checkPermissionTo('sales.cancel'), 'role_management_assigned' => $role->checkPermissionTo('roles.manage')])->all(),
            'active_super_admin_access_preflight' => User::withoutGlobalScopes()->where('status', 'active')->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin')->where('guard_name', 'web'))
                ->with(['roles.permissions', 'permissions'])->get()->map(fn (User $user) => ['user_id' => $user->id, 'company_id' => $user->company_id,
                    'missing_abilities' => array_values(array_filter($administrationAbilities, fn ($ability) => ! $user->can($ability)))])->all(),
            'direct_cancellation_grants' => DB::table('model_has_permissions as grants')->join('permissions', 'permissions.id', '=', 'grants.permission_id')
                ->join('users', 'users.id', '=', 'grants.model_id')->where('grants.model_type', (new User)->getMorphClass())
                ->where('permissions.guard_name', 'web')->where('permissions.name', 'sales.cancel')->select('users.id as user_id', 'users.company_id')->get()->all(),
            'sales_authorization' => [
                'view_and_receipts' => 'sales.view plus company/branch/sale scope; no print receipt requirement',
                'pos_and_create' => 'sales.create (legacy create sales/access pos grants remain explicit aliases)',
                'cancel' => 'sales.cancel AND sales.view AND accessible completed sale; no Super Admin or edit sales bypass',
                'payments' => 'sales.edit OR receive sale payments, plus sale access and transactional balance checks',
                'discount' => 'sales.discount; independently checked in POS, sale service and sale model',
                'backdate' => 'sales.backdate plus existing date/branch/cashbook validation; no identity bypass',
                'credit' => 'create credit sales; create unassigned credit sales additionally required when no customer selected',
                'refund' => 'sales.refund retained independently; no sales refund endpoint currently exists and cancellation does not issue a cash refund',
                'exports' => 'report access plus reports.export/export pdf/export excel legacy grants',
            ],
            'administrative_safety' => [
                'super_admin_editing' => 'roles.manage plus Super Admin identity required to edit or assign Super Admin; identity alone grants no business ability',
                'last_administrator' => 'Role saves cannot remove role-management access from every active company administrator',
                'console_recovery' => 'php artisan roles:recover-admin COMPANY_ID USER_ID --force; grants only direct roles.manage to an active existing company Super Admin',
                'data_scope_exceptions' => 'Super Admin remains company-wide for branch visibility. Explicit platform is_system_owner scope and customer-portal settings access remain; they never authorize sales cancellation.',
                'seeders' => 'Initialize new templates/users only; preserve existing grants, assignments and passwords',
            ],
            'notes' => ['Read-only; dynamic permission expressions require manual review.', 'print receipt is retained historically but excluded from the role catalog.', 'Manufacturing permissions are offered only when the company capability is enabled.', 'Super Admin has no blanket Gate bypass. Direct user grants and legacy aliases remain effective independently of role grants.'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
