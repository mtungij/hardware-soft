<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\User;
use Database\Seeders\AutoPartsCategorySeeder;
use Database\Seeders\AutoPartsProductSeeder;
use Database\Seeders\AutoPartsUnitSeeder;
use Database\Seeders\HardwareCategorySeeder;
use Database\Seeders\HardwareProductSeeder;
use Database\Seeders\HardwareUnitSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.auth');
uses([WithFileUploads::class]);

state([
    'step' => 1,
    'company_name' => '',
    'business_type' => 'Hardware Store',
    'tin_number' => '',
    'vrn_number' => '',
    'phone' => '',
    'whatsapp_number' => '',
    'email' => '',
    'address' => '',
    'region' => '',
    'district' => '',
    'country' => 'Tanzania',
    'logo_upload' => null,
    'description' => '',
    'currency' => 'TZS',
    'timezone' => 'Africa/Dar_es_Salaam',
    'language' => 'sw',
    'inventory_stock_mode' => 'warehouse',
    'manufacturing_enabled' => false,
    'admin_name' => '',
    'admin_phone' => '',
    'admin_email' => '',
    'admin_password' => '',
    'admin_password_confirmation' => '',
    'admin_photo' => null,
    'branch_name' => 'Main Branch',
    'branch_code' => 'MAIN',
    'branch_phone' => '',
    'branch_email' => '',
    'branch_address' => '',
    'branch_region' => '',
    'branch_district' => '',
    'branch_manager_name' => '',
    'branch_status' => 'active',
    'branch_is_default' => true,
    'production_branch' => 'primary',
    'raw_materials_store_name' => 'Raw Materials Store',
    'production_area_name' => 'Production Area',
    'curing_yard_name' => 'Curing Yard',
    'finished_goods_store_name' => 'Finished Goods Store',
    'location_sources' => [
        'raw_materials' => 'recommended',
        'production_area' => 'recommended',
        'curing_yard' => 'recommended',
        'finished_goods' => 'recommended',
    ],
    'location_rename_open' => [
        'raw_materials' => false,
        'production_area' => false,
        'curing_yard' => false,
        'finished_goods' => false,
    ],
    'shared_location_confirmed' => false,
    'machine_section_open' => false,
    'machine_name' => '',
    'machine_code' => '',
    'machine_daily_capacity' => '',
    'default_sellable_after_days' => '10',
    'default_curing_days' => '14',
    'quality_control_preference' => false,
]);

$validationRules = fn () => [
    'company_name' => ['required', 'string', 'max:255'],
    'business_type' => ['required', 'in:Hardware Store,Auto Spare Parts'],
    'tin_number' => ['nullable', 'string', 'max:100'],
    'vrn_number' => ['nullable', 'string', 'max:100'],
    'phone' => ['required', 'string', 'max:30'],
    'whatsapp_number' => ['required', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:1000'],
    'region' => ['nullable', 'string', 'max:100'],
    'district' => ['nullable', 'string', 'max:100'],
    'country' => ['required', 'string', 'max:100'],
    'logo_upload' => ['nullable', 'image', 'max:2048'],
    'description' => ['nullable', 'string', 'max:1500'],
    'currency' => ['required', 'string', 'max:10'],
    'timezone' => ['required', 'string', 'max:100'],
    'language' => ['required', 'in:sw,en'],
    'inventory_stock_mode' => ['required', 'in:warehouse,direct'],
    'manufacturing_enabled' => ['required', 'boolean'],
    'admin_name' => ['required', 'string', 'max:255'],
    'admin_phone' => ['required', 'string', 'max:30'],
    'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
    'admin_password' => ['required', 'confirmed', Password::defaults()],
    'admin_photo' => ['nullable', 'image', 'max:2048'],
    'branch_name' => ['required', 'string', 'max:255'],
    'branch_code' => ['required', 'string', 'max:50'],
    'branch_phone' => ['nullable', 'string', 'max:30'],
    'branch_email' => ['nullable', 'email', 'max:255'],
    'branch_address' => ['nullable', 'string', 'max:1000'],
    'branch_region' => ['nullable', 'string', 'max:100'],
    'branch_district' => ['nullable', 'string', 'max:100'],
    'branch_manager_name' => ['nullable', 'string', 'max:255'],
    'branch_status' => ['required', 'in:active,inactive'],
    'branch_is_default' => ['boolean'],
    'production_branch' => [$this->manufacturing_enabled ? 'required' : 'nullable', 'in:primary'],
    'raw_materials_store_name' => [$this->manufacturing_enabled ? 'required' : 'nullable', 'string', 'max:255'],
    'production_area_name' => [$this->manufacturing_enabled ? 'required' : 'nullable', 'string', 'max:255'],
    'curing_yard_name' => [$this->manufacturing_enabled ? 'required' : 'nullable', 'string', 'max:255'],
    'finished_goods_store_name' => [$this->manufacturing_enabled ? 'required' : 'nullable', 'string', 'max:255'],
    'machine_name' => [$this->manufacturing_enabled && $this->machine_section_open && (filled($this->machine_code) || filled($this->machine_daily_capacity)) ? 'required' : 'nullable', 'string', 'max:255'],
    'machine_code' => ['nullable', 'string', 'max:100'],
    'machine_daily_capacity' => ['nullable', 'numeric', 'gt:0'],
    'default_sellable_after_days' => ['nullable', 'integer', 'gt:0', 'lte:default_curing_days'],
    'default_curing_days' => ['nullable', 'integer', 'gt:0'],
    'quality_control_preference' => ['boolean'],
];

rules(fn () => $this->validationRules());

$updatedRegion = function () {
    $this->district = '';
};

$updatedBranchRegion = function () {
    $this->branch_district = '';
};

$activeSteps = fn (): array => $this->manufacturing_enabled
    ? [1 => __('setup.steps.business'), 2 => __('setup.steps.owner'), 3 => __('setup.steps.branch'), 4 => __('setup.steps.production'), 5 => __('setup.steps.review')]
    : [1 => __('setup.steps.business'), 2 => __('setup.steps.owner'), 3 => __('setup.steps.branch'), 5 => __('setup.steps.review')];

$stepPosition = fn (): int => array_search($this->step, array_keys($this->activeSteps()), true) + 1;

$updatedManufacturingEnabled = function ($value) {
    $this->manufacturing_enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    if (! $this->manufacturing_enabled && $this->step === 4) {
        $this->step = 5;
    }
};

$productionLocationDefinitions = fn (): array => [
    'raw_materials' => [
        'name_field' => 'raw_materials_store_name',
        'recommended_name' => 'Raw Materials Store',
        'title' => __('setup.raw_materials_store'),
        'type' => __('setup.location_types.warehouse'),
        'description' => __('setup.location_descriptions.raw_materials'),
        'icon' => 'archive-box',
        'tone' => 'amber',
    ],
    'production_area' => [
        'name_field' => 'production_area_name',
        'recommended_name' => 'Production Area',
        'title' => __('setup.production_area'),
        'type' => __('setup.location_types.production'),
        'description' => __('setup.location_descriptions.production_area'),
        'icon' => 'building-office',
        'tone' => 'cyan',
    ],
    'curing_yard' => [
        'name_field' => 'curing_yard_name',
        'recommended_name' => 'Curing Yard',
        'title' => __('setup.curing_yard'),
        'type' => __('setup.location_types.curing'),
        'description' => __('setup.location_descriptions.curing_yard'),
        'icon' => 'beaker',
        'tone' => 'blue',
    ],
    'finished_goods' => [
        'name_field' => 'finished_goods_store_name',
        'recommended_name' => 'Finished Goods Store',
        'title' => __('setup.finished_goods_store'),
        'type' => __('setup.location_types.store'),
        'description' => __('setup.location_descriptions.finished_goods'),
        'icon' => 'building-storefront',
        'tone' => 'emerald',
    ],
];

$compatibleSetupLocations = function (string $role): array {
    if ($this->inventory_stock_mode !== 'warehouse') {
        return [];
    }

    return ['main_store' => __('setup.main_store')];
};

$selectProductionLocation = function (string $role, string $source) {
    $definition = $this->productionLocationDefinitions()[$role] ?? null;
    if (! $definition || ! in_array($source, ['recommended', 'main_store'], true)) {
        return;
    }

    if ($source === 'main_store' && ! array_key_exists($source, $this->compatibleSetupLocations($role))) {
        return;
    }

    $this->location_sources[$role] = $source;
    $this->{$definition['name_field']} = $source === 'recommended'
        ? $definition['recommended_name']
        : 'Main Store';
    $this->location_rename_open[$role] = false;
    $this->shared_location_confirmed = false;
};

$toggleLocationRename = function (string $role) {
    $definition = $this->productionLocationDefinitions()[$role] ?? null;
    if (! $definition) {
        return;
    }

    $this->location_rename_open[$role] = ! ($this->location_rename_open[$role] ?? false);
    if ($this->location_rename_open[$role]) {
        $this->location_sources[$role] = 'custom';
    }
    $this->shared_location_confirmed = false;
};

$toggleMachineSection = function () {
    $this->machine_section_open = ! $this->machine_section_open;
};

$locationAssignmentsHaveDuplicates = function (): bool {
    $names = collect($this->productionLocationDefinitions())
        ->map(fn ($definition) => mb_strtolower(trim((string) $this->{$definition['name_field']})))
        ->filter();

    return $names->unique()->count() !== $names->count();
};

$updatedInventoryStockMode = function ($value) {
    if ($value !== 'warehouse') {
        foreach ($this->location_sources as $role => $source) {
            if ($source === 'main_store') {
                $this->selectProductionLocation($role, 'recommended');
            }
        }
    }
};

$updatedRawMaterialsStoreName = fn () => $this->shared_location_confirmed = false;
$updatedProductionAreaName = fn () => $this->shared_location_confirmed = false;
$updatedCuringYardName = fn () => $this->shared_location_confirmed = false;
$updatedFinishedGoodsStoreName = fn () => $this->shared_location_confirmed = false;

$stepFields = function (int $step): array {
    return match ($step) {
        1 => ['company_name', 'business_type', 'tin_number', 'vrn_number', 'phone', 'whatsapp_number', 'email', 'address', 'region', 'district', 'country', 'logo_upload', 'description', 'currency', 'timezone', 'language', 'inventory_stock_mode', 'manufacturing_enabled'],
        2 => ['admin_name', 'admin_phone', 'admin_email', 'admin_password', 'admin_password_confirmation', 'admin_photo'],
        3 => ['branch_name', 'branch_code', 'branch_phone', 'branch_email', 'branch_address', 'branch_region', 'branch_district', 'branch_manager_name', 'branch_status', 'branch_is_default'],
        4 => ['production_branch', 'raw_materials_store_name', 'production_area_name', 'curing_yard_name', 'finished_goods_store_name', 'machine_name', 'machine_code', 'machine_daily_capacity', 'default_sellable_after_days', 'default_curing_days', 'quality_control_preference'],
        default => [],
    };
};

$next = function () {
    $rules = collect($this->validationRules())->only($this->stepFields($this->step))->all();
    $this->validate($rules);
    if ($this->step === 4 && $this->locationAssignmentsHaveDuplicates() && ! $this->shared_location_confirmed) {
        throw ValidationException::withMessages([
            'shared_location_confirmed' => __('setup.validation.confirm_shared_location'),
        ]);
    }
    $steps = array_keys($this->activeSteps());
    $position = array_search($this->step, $steps, true);
    $this->step = $steps[min(count($steps) - 1, $position + 1)];
};

$back = function () {
    $steps = array_keys($this->activeSteps());
    $position = array_search($this->step, $steps, true);
    $this->step = $steps[max(0, $position - 1)];
};

$goTo = function (int $step) {
    $steps = array_keys($this->activeSteps());
    if (in_array($step, $steps, true) && array_search($step, $steps, true) < array_search($this->step, $steps, true)) {
        $this->step = $step;
    }
};

$complete = function () {
    $data = $this->validate();

    if (! $data['manufacturing_enabled']) {
        foreach (['production_branch', 'raw_materials_store_name', 'production_area_name', 'curing_yard_name', 'finished_goods_store_name', 'machine_name', 'machine_code', 'machine_daily_capacity', 'default_sellable_after_days', 'default_curing_days'] as $field) {
            $data[$field] = null;
        }
        $data['quality_control_preference'] = false;
    } else {
        if ($this->locationAssignmentsHaveDuplicates() && ! $this->shared_location_confirmed) {
            throw ValidationException::withMessages([
                'shared_location_confirmed' => __('setup.validation.confirm_shared_location'),
            ]);
        }

        if (! $this->machine_section_open) {
            foreach (['machine_name', 'machine_code', 'machine_daily_capacity'] as $field) {
                $data[$field] = null;
            }
        }
    }

    $result = DB::transaction(function () use ($data) {
        $branchHasCompanyId = Schema::hasColumn('branches', 'company_id');
        $stockLocationHasCompanyId = Schema::hasColumn('stock_locations', 'company_id');
        $userHasCompanyId = Schema::hasColumn('users', 'company_id');
        $settingHasCompanyId = Schema::hasColumn('settings', 'company_id');

        $logoPath = $this->logo_upload?->store('company-logos', 'public');
        $photoPath = $this->admin_photo?->store('profile-photos', 'public');

        $company = Company::query()->create([
            'company_name' => $data['company_name'],
            'business_type' => $data['business_type'],
            'tin_number' => $data['tin_number'] ?: null,
            'vrn_number' => $data['vrn_number'] ?: null,
            'phone' => $data['phone'],
            'whatsapp_number' => $data['whatsapp_number'],
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'region' => $data['region'] ?: null,
            'district' => $data['district'] ?: null,
            'country' => $data['country'],
            'logo' => $logoPath,
            'description' => $data['description'] ?: null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'language' => $data['language'],
            'manufacturing_enabled' => (bool) $data['manufacturing_enabled'],
        ]);

        $branchAttributes = [
            'code' => strtoupper($data['branch_code'] ?: 'MAIN'),
        ];

        if ($branchHasCompanyId) {
            $branchAttributes['company_id'] = $company->id;
        }

        $branchValues = [
            'name' => $data['branch_name'],
            'phone' => $data['branch_phone'] ?: $data['phone'],
            'email' => $data['branch_email'] ?: $data['email'],
            'address' => $data['branch_address'] ?: $data['address'],
            'region' => $data['branch_region'] ?: $data['region'],
            'district' => $data['branch_district'] ?: $data['district'],
            'manager_name' => $data['branch_manager_name'] ?: $data['admin_name'],
            'status' => $data['branch_status'],
            'is_default' => (bool) $data['branch_is_default'],
        ];

        if ($branchHasCompanyId) {
            $branchValues['company_id'] = $company->id;
        }

        $branch = Branch::query()->updateOrCreate($branchAttributes, $branchValues);

        $dispensingAttributes = [
            'branch_id' => $branch->id,
            'code' => 'DISPENSING',
            'type' => 'dispensing',
        ];

        if ($stockLocationHasCompanyId) {
            $dispensingAttributes['company_id'] = $company->id;
        }

        $dispensingLocation = StockLocation::query()->firstOrCreate($dispensingAttributes, [
            'name' => 'Dispensing Area',
            'status' => 'active',
        ]);
        $dispensingLocation->forceFill(['status' => 'active'])->save();

        $mainStoreLocation = null;
        if ($data['inventory_stock_mode'] === 'warehouse') {
            $mainStoreAttributes = [
                'branch_id' => $branch->id,
                'code' => 'MAIN-STORE',
                'type' => 'store',
            ];

            if ($stockLocationHasCompanyId) {
                $mainStoreAttributes['company_id'] = $company->id;
            }

            $mainStoreLocation = StockLocation::query()->firstOrCreate($mainStoreAttributes, [
                'name' => 'Main Store',
                'status' => 'active',
            ]);
            $mainStoreLocation->forceFill(['status' => 'active'])->save();
        } else {
            StockLocation::query()
                ->where('branch_id', $branch->id)
                ->where('code', 'MAIN-STORE')
                ->when($stockLocationHasCompanyId, fn ($query) => $query->where('company_id', $company->id))
                ->update(['status' => 'inactive']);
        }

        $productionLocations = collect();
        if ($data['manufacturing_enabled']) {
            $locationDefinitions = [
                ['code' => 'RAW-MATERIALS', 'name' => $data['raw_materials_store_name'], 'type' => 'warehouse', 'sellable' => false],
                ['code' => 'PRODUCTION-AREA', 'name' => $data['production_area_name'], 'type' => 'other', 'sellable' => false],
                ['code' => 'CURING-YARD', 'name' => $data['curing_yard_name'], 'type' => 'curing', 'sellable' => false],
                ['code' => 'FINISHED-GOODS', 'name' => $data['finished_goods_store_name'], 'type' => 'store', 'sellable' => true],
            ];

            foreach ($locationDefinitions as $definition) {
                $query = StockLocation::query()->where('branch_id', $branch->id)
                    ->when($stockLocationHasCompanyId, fn ($query) => $query->where('company_id', $company->id));
                $location = $query->where(fn ($locationQuery) => $locationQuery
                    ->where('code', $definition['code'])
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower(trim($definition['name']))]))
                    ->first();

                if (! $location) {
                    $location = new StockLocation;
                    if ($stockLocationHasCompanyId) {
                        $location->company_id = $company->id;
                    }
                    $location->branch_id = $branch->id;
                }

                $location->forceFill([
                    'code' => $definition['code'],
                    'name' => trim($definition['name']),
                    'type' => $definition['type'],
                    'status' => 'active',
                    'is_active' => true,
                    'can_receive_stock' => true,
                    'can_issue_stock' => true,
                    'can_transfer' => true,
                    'can_sell' => $definition['sellable'],
                    'is_sellable' => $definition['sellable'],
                    'is_warehouse' => $definition['type'] === 'warehouse',
                ])->save();
                $productionLocations->put($definition['code'], $location);
            }
        }

        $enableWarehouse = $data['inventory_stock_mode'] === 'warehouse';

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(RolePermissionSeeder::class)->run();
        $permissions = Permission::query()->where('guard_name', 'web')->get();
        $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $userData = [
            'branch_id' => $branch->id,
            'name' => $data['admin_name'],
            'phone' => $data['admin_phone'],
            'email' => $data['admin_email'],
            'profile_photo' => $photoPath,
            'status' => 'active',
            'is_system_owner' => false,
            'password' => $data['admin_password'],
            'email_verified_at' => now(),
        ];

        if ($userHasCompanyId) {
            $userData['company_id'] = $company->id;
        }

        $user = User::query()->create($userData);
        $user->assignRole($role);
        $user->syncPermissions($permissions);

        $machine = null;
        if ($data['manufacturing_enabled'] && filled($data['machine_name'])) {
            $machine = Machine::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => trim($data['machine_name'])],
                [
                    'branch_id' => $branch->id,
                    'code' => filled($data['machine_code']) ? strtoupper(trim($data['machine_code'])) : null,
                    'daily_capacity' => filled($data['machine_daily_capacity']) ? $data['machine_daily_capacity'] : null,
                    'capacity_unit' => 'pcs_per_day',
                    'status' => Machine::STATUS_ACTIVE,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );
        }

        if ($company->business_type === 'Auto Spare Parts') {
            (new AutoPartsCategorySeeder($company->id, $branch->id))->run();
            (new AutoPartsUnitSeeder($company->id, $branch->id))->run();
            (new AutoPartsProductSeeder($company->id, $branch->id))->run();
        } else {
            (new HardwareCategorySeeder($company->id, $branch->id))->run();
            (new HardwareUnitSeeder($company->id, $branch->id))->run();
            (new HardwareProductSeeder($company->id, $branch->id))->run();
        }

        $setting = $settingHasCompanyId
            ? Setting::query()->firstOrNew(['company_id' => $company->id])
            : (Setting::query()->first() ?: new Setting);
        $settingData = [
            'company_name' => $company->company_name,
            'business_type' => $company->business_type,
            'tin_number' => $company->tin_number,
            'vrn_number' => $company->vrn_number,
            'company_logo' => $company->logo,
            'company_phone' => $company->phone,
            'whatsapp_number' => $company->whatsapp_number,
            'company_email' => $company->email,
            'company_address' => $company->address,
            'region' => $company->region,
            'district' => $company->district,
            'country' => $company->country,
            'business_description' => $company->description,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
            'language' => $company->language,
            'default_branch_id' => $branch->id,
            'enable_warehouse' => $enableWarehouse,
            'allow_direct_stock_in' => ! $enableWarehouse,
            'allow_sales_from_store' => false,
            'default_stock_location_id' => $enableWarehouse ? $mainStoreLocation?->id : $dispensingLocation->id,
            'theme_color' => '#06b6d4',
            'system_initialized' => true,
        ];

        if ($settingHasCompanyId) {
            $settingData['company_id'] = $company->id;
        }

        $setting->fill($settingData)->save();

        return ['company' => $company, 'user' => $user, 'branch' => $branch, 'locations' => $productionLocations, 'machine' => $machine];
    });

    if ($data['manufacturing_enabled']) {
        Auth::loginUsingId($result['user']->id);
        session()->regenerate();
        session()->put('production_setup_suggestions', [
            'default_sellable_after_days' => $data['default_sellable_after_days'],
            'default_curing_days' => $data['default_curing_days'],
            'quality_control_preference' => (bool) $data['quality_control_preference'],
        ]);
        $this->redirectRoute('production.setup-checklist', navigate: false);

        return;
    }

    session()->flash('success', 'System setup completed. Sign in with your Super Admin account.');
    $this->redirectRoute('login', navigate: false);
};

?>

<div class="min-h-screen bg-slate-100 px-4 py-8 text-slate-900 dark:bg-slate-950 dark:text-white">
    <div class="mx-auto max-w-6xl">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <div class="grid h-12 w-12 place-items-center overflow-hidden rounded-xl bg-white p-1.5 shadow-soft">
                    <img src="{{ asset('images/hardex.png') }}" alt="Hardex" class="h-full w-full object-contain">
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xl font-black text-navy-900 dark:text-white">Hardex Hardware ERP</p>
                    <p class="text-sm font-semibold text-slate-500">First system setup</p>
                </div>
            </div>
            <a href="{{ route('login') }}" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black text-slate-600 transition hover:border-build-orange dark:border-slate-700 dark:text-slate-200">
                Back to Login
            </a>
        </div>

        <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm font-black text-navy-900 dark:text-white">{{ __('setup.progress', ['current' => $this->stepPosition(), 'total' => count($this->activeSteps())]) }}</p>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ $this->activeSteps()[$step] }}</p>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-cyan-500 transition-all duration-300" style="width: {{ ($this->stepPosition() / count($this->activeSteps())) * 100 }}%"></div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <aside class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-soft dark:border-slate-800 dark:bg-slate-900 lg:sticky lg:top-6 lg:self-start lg:overflow-visible lg:p-4">
                <div class="flex min-w-max gap-2 lg:block lg:min-w-0 lg:space-y-2">
                @foreach ($this->activeSteps() as $number => $label)
                    <button type="button" wire:click="goTo({{ $number }})" class="flex w-52 shrink-0 items-center gap-3 rounded-xl px-3 py-3 text-left transition lg:w-full {{ $step === $number ? 'bg-cyan-50 text-cyan-600 dark:bg-cyan-500/15 dark:text-cyan-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-white/5' }}">
                        <span class="grid h-9 w-9 place-items-center rounded-lg {{ $this->stepPosition() >= $loop->iteration ? 'bg-cyan-500 text-white' : 'bg-slate-100 dark:bg-white/10' }}">{{ $loop->iteration }}</span>
                        <span class="min-w-0 text-sm font-black leading-tight">{{ $label }}</span>
                    </button>
                @endforeach
                </div>
            </aside>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                @if ($step === 1)
                    <div>
                        <h1 class="text-2xl font-black">Hardware Business Information</h1>
                        <p class="mt-1 text-sm text-slate-500">Set the identity, contact details, branding, and localization defaults for this ERP.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Company Name" name="company_name" wire:model="company_name" required />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Business Type
                                <select wire:model="business_type" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="Hardware Store">Hardware</option>
                                    <!-- <option value="Auto Spare Parts">Auto Spare Parts</option> -->
                                </select>
                                @error('business_type') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <x-form-input label="TIN Number" name="tin_number" wire:model="tin_number" />
                            <x-form-input label="VRN Number" name="vrn_number" wire:model="vrn_number" />
                            <x-form-input label="Phone Number" name="phone" wire:model="phone" required />
                            <x-form-input label="WhatsApp Number" name="whatsapp_number" wire:model="whatsapp_number" required />
                            <x-form-input label="Email Address" name="email" wire:model="email" type="email" />
                            <x-tanzania-location-selects :region="$region" :district="$district" region-model="region" district-model="district" region-name="region" district-name="district" />
                            <x-form-input label="Country" name="country" wire:model="country" required />
                            <x-form-input label="Default Currency" name="currency" wire:model="currency" required />
                            <x-form-input label="Timezone" name="timezone" wire:model="timezone" required />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Language
                                <select wire:model="language" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="sw">Kiswahili</option>
                                    <option value="en">English</option>
                                </select>
                            </label>
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Company Logo
                                <input wire:model="logo_upload" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                @error('logo_upload') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <div class="md:col-span-2"><x-form-textarea label="Physical Address" name="address" wire:model="address" rows="3" /></div>
                            <div class="md:col-span-2"><x-form-textarea label="Business Description" name="description" wire:model="description" rows="3" /></div>
                         <div class="md:col-span-2">
  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-wide text-cyan-600 dark:text-cyan-300">
                Mfumo wa Kupokea na Kuuza Mzigo
            </p>

            <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-white">
                Unapokea mzigo kupitia ghala au moja kwa moja dukani?
            </h2>

            <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">
                Chagua mfumo unaofanana na namna hardware yako inavyofanya kazi kila siku.
            </p>
        </div>

        <span class="inline-flex w-fit items-center rounded-full bg-cyan-100 px-3 py-1 text-xs font-black text-cyan-900 dark:bg-cyan-500/20 dark:text-cyan-100">
            Lazima uchague
        </span>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">

        {{-- WITH WAREHOUSE --}}
        <label class="group cursor-pointer rounded-2xl border p-4 transition hover:border-cyan-400 hover:bg-cyan-50 dark:hover:border-cyan-400 dark:hover:bg-cyan-500/5 {{ $inventory_stock_mode === 'warehouse' ? 'border-cyan-500 bg-cyan-100 shadow-lg shadow-cyan-500/10 ring-2 ring-cyan-500/20 dark:border-cyan-400 dark:bg-cyan-500/10 dark:ring-cyan-400/20' : 'border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' }}">
            <input type="radio" wire:model.live="inventory_stock_mode" value="warehouse" class="sr-only">

            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 {{ $inventory_stock_mode === 'warehouse' ? 'border-cyan-600 bg-cyan-600 text-white dark:border-cyan-300 dark:bg-cyan-400 dark:text-slate-950' : 'border-slate-300 bg-white text-transparent group-hover:border-cyan-400 dark:border-slate-600 dark:bg-slate-900' }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.32a1 1 0 0 1-1.42 0L3.29 9.229a1 1 0 1 1 1.42-1.408l4.04 4.077 6.54-6.602a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-black text-slate-950 dark:text-white">
                                    Nina Ghala Kuu
                                </h3>

                               <span class="inline-flex items-center rounded-full bg-slate-900 px-3 py-1 text-xs font-black text-white dark:bg-white dark:text-slate-900">
    Hardware yenye Ghala Kuu
</span>
                            </div>

                            <p class="mt-3 text-sm font-bold leading-6 text-slate-800 dark:text-slate-100">
                                Chagua hii kama mzigo unaingia kwanza kwenye ghala/store kabla ya kupelekwa sehemu ya mauzo.
                            </p>
                        </div>

                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white shadow-lg shadow-cyan-500/20 dark:bg-cyan-400 dark:text-slate-950">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 21h18" />
                                <path d="M5 21V8l7-4 7 4v13" />
                                <path d="M9 21v-8h6v8" />
                                <path d="M9 10h.01" />
                                <path d="M15 10h.01" />
                            </svg>
                        </span>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-white">
                        <p class="text-sm font-black text-black">
                            Mfano:
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-extrabold text-black">
                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Mzigo unaingia Ghala Kuu
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unahamishiwa Sehemu ya Mauzo
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unauzwa kwa mteja
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </label>

        {{-- WITHOUT WAREHOUSE --}}
        <label class="group cursor-pointer rounded-2xl border p-4 transition hover:border-cyan-400 hover:bg-cyan-50 dark:hover:border-cyan-400 dark:hover:bg-cyan-500/5 {{ $inventory_stock_mode === 'direct' ? 'border-cyan-500 bg-cyan-100 shadow-lg shadow-cyan-500/10 ring-2 ring-cyan-500/20 dark:border-cyan-400 dark:bg-cyan-500/10 dark:ring-cyan-400/20' : 'border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' }}">
            <input type="radio" wire:model.live="inventory_stock_mode" value="direct" class="sr-only">

            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 {{ $inventory_stock_mode === 'direct' ? 'border-cyan-600 bg-cyan-600 text-white dark:border-cyan-300 dark:bg-cyan-400 dark:text-slate-950' : 'border-slate-300 bg-white text-transparent group-hover:border-cyan-400 dark:border-slate-600 dark:bg-slate-900' }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.32a1 1 0 0 1-1.42 0L3.29 9.229a1 1 0 1 1 1.42-1.408l4.04 4.077 6.54-6.602a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-black text-slate-950 dark:text-white">
                                    Sina Ghala Kuu
                                </h3>

                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-black text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/20 dark:text-amber-100">
                                    Inafaa kwa hardware ndogo
                                </span>
                            </div>

                            <p class="mt-3 text-sm font-bold leading-6 text-slate-800 dark:text-slate-100">
                                Chagua hii kama mzigo unaingia moja kwa moja kwenye eneo la mauzo na kuuzwa hapo hapo.
                            </p>
                        </div>

                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-cyan-500 text-white shadow-lg shadow-cyan-500/20 dark:bg-cyan-400 dark:text-slate-950">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 10h16" />
                                <path d="M5 10l1-5h12l1 5" />
                                <path d="M6 10v9h12v-9" />
                                <path d="M9 19v-5h6v5" />
                            </svg>
                        </span>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-white">
                        <p class="text-sm font-black text-black">
                            Mfano:
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-extrabold text-black">
                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Mzigo unaingia Sehemu ya Mauzo
                            </span>

                            <span class="text-lg font-black text-cyan-600">→</span>

                            <span class="rounded-lg bg-slate-100 px-3 py-2">
                                Unauzwa moja kwa moja kwa mteja
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </label>
    </div>

    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-bold leading-5 text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
        Unaweza kuanza na mfumo unaolingana na biashara yako. Mfumo utatumia chaguo hili kupanga namna stock inavyoingia na kuuzwa.
    </div>

    @error('inventory_stock_mode')
        <span class="mt-2 block text-xs font-semibold text-red-600">
            {{ $message }}
        </span>
    @enderror
</div>
</div>
                            <div class="md:col-span-2 rounded-2xl border border-cyan-200 bg-cyan-50/60 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10 sm:p-5">
                                <p class="text-xs font-black uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('setup.business_operations') }}</p>
                                <h2 class="mt-1 text-xl font-black">{{ __('setup.manufacturing_question') }}</h2>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <label class="cursor-pointer rounded-xl border p-4 transition {{ ! $manufacturing_enabled ? 'border-cyan-500 bg-white ring-2 ring-cyan-500/20 dark:bg-slate-950' : 'border-slate-200 bg-white/70 dark:border-slate-700 dark:bg-slate-950/60' }}">
                                        <input type="radio" wire:model.live="manufacturing_enabled" value="0" class="sr-only">
                                        <span class="block text-base font-black">{{ __('setup.manufacturing_no') }}</span>
                                        <span class="mt-1 block text-sm text-slate-500">{{ __('setup.manufacturing_no_help') }}</span>
                                    </label>
                                    <label class="cursor-pointer rounded-xl border p-4 transition {{ $manufacturing_enabled ? 'border-cyan-500 bg-white ring-2 ring-cyan-500/20 dark:bg-slate-950' : 'border-slate-200 bg-white/70 dark:border-slate-700 dark:bg-slate-950/60' }}">
                                        <input type="radio" wire:model.live="manufacturing_enabled" value="1" class="sr-only">
                                        <span class="block text-base font-black">{{ __('setup.manufacturing_yes') }}</span>
                                        <span class="mt-1 block text-sm text-slate-500">{{ __('setup.manufacturing_yes_help') }}</span>
                                    </label>
                                </div>
                                @error('manufacturing_enabled')<span class="mt-2 block text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </div>
                @elseif ($step === 2)
                    <div>
                        <h1 class="text-2xl font-black">Super Admin Account</h1>
                        <p class="mt-1 text-sm text-slate-500">Create the first system owner with unrestricted ERP access.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Full Name" name="admin_name" wire:model="admin_name" required />
                            <x-form-input label="Phone Number" name="admin_phone" wire:model="admin_phone" required />
                            <x-form-input label="Email Address" name="admin_email" wire:model="admin_email" type="email" required />
                            <x-cropped-image-upload label="Profile Picture" name="admin_photo" wire:model="admin_photo" />
                            <x-form-input label="Password" name="admin_password" wire:model="admin_password" type="password" required />
                            <x-form-input label="Confirm Password" name="admin_password_confirmation" wire:model="admin_password_confirmation" type="password" required />
                        </div>
                    </div>
                @elseif ($step === 3)
                    <div>
                        <h1 class="text-2xl font-black">Branch Information</h1>
                        <p class="mt-1 text-sm text-slate-500">Create the first operational branch. If no branch exists, this becomes MAIN.</p>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <x-form-input label="Branch Name" name="branch_name" wire:model="branch_name" required />
                            <x-form-input label="Branch Code" name="branch_code" wire:model="branch_code" required />
                            <x-form-input label="Phone Number" name="branch_phone" wire:model="branch_phone" />
                            <x-form-input label="Email" name="branch_email" wire:model="branch_email" type="email" />
                            <x-tanzania-location-selects :region="$branch_region" :district="$branch_district" region-model="branch_region" district-model="branch_district" region-name="branch_region" district-name="branch_district" />
                            <x-form-input label="Manager Name" name="branch_manager_name" wire:model="branch_manager_name" />
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Status
                                <select wire:model="branch_status" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                                <input type="checkbox" wire:model="branch_is_default" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                                Default Branch: Yes
                            </label>
                            <div class="md:col-span-2"><x-form-textarea label="Address" name="branch_address" wire:model="branch_address" rows="3" /></div>
                        </div>
                    </div>
                @elseif ($step === 4 && $manufacturing_enabled)
                    <div>
                        <h1 class="text-2xl font-black">{{ __('setup.production_setup') }}</h1>
                        <p class="mt-1 text-sm text-slate-500">{{ __('setup.production_setup_help') }}</p>
                        <div class="mt-6">
                            <label for="production_branch" class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                {{ __('setup.production_branch') }}
                                <select id="production_branch" wire:model="production_branch" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                                    <option value="primary">{{ $branch_name }} ({{ $branch_code }})</option>
                                </select>
                            </label>
                        </div>

                        <div class="mt-6 grid min-w-0 gap-4 md:grid-cols-2">
                            @foreach ($this->productionLocationDefinitions() as $role => $definition)
                                @php
                                    $nameField = $definition['name_field'];
                                    $currentName = $this->{$nameField};
                                @endphp
                                <x-setup.production-location-card
                                    :role="$role"
                                    :definition="$definition"
                                    :name-field="$nameField"
                                    :current-name="$currentName"
                                    :source="$location_sources[$role]"
                                    :rename-open="$location_rename_open[$role]"
                                    :existing-locations="$this->compatibleSetupLocations($role)"
                                />
                            @endforeach
                        </div>

                        @if ($this->locationAssignmentsHaveDuplicates())
                            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" role="alert" aria-live="polite">
                                <div class="flex items-start gap-3">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.17 2.357-1.17 3.03 0l6.28 10.91c.67 1.165-.171 2.62-1.515 2.62H3.72c-1.344 0-2.185-1.455-1.515-2.62l6.28-10.91ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 7.75a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                                    <div>
                                        <h2 class="font-black">{{ __('setup.shared_location_title') }}</h2>
                                        <p class="mt-1 text-sm leading-6">{{ __('setup.shared_location_warning') }}</p>
                                        <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm font-bold">
                                            <input type="checkbox" wire:model.live="shared_location_confirmed" class="mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                                            <span>{{ __('setup.confirm_shared_location') }}</span>
                                        </label>
                                        @error('shared_location_confirmed')<span class="mt-2 block text-xs font-semibold text-red-700 dark:text-red-300" role="alert">{{ $message }}</span>@enderror
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700">
                            <button type="button" wire:click="toggleMachineSection" class="flex min-h-14 w-full items-center justify-between gap-4 px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-white/5" aria-expanded="{{ $machine_section_open ? 'true' : 'false' }}" aria-controls="first-machine-fields">
                                <span class="flex items-center gap-3 font-black">
                                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-200" aria-hidden="true">
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v6.5h-6.5a.75.75 0 0 0 0 1.5h6.5v6.5a.75.75 0 0 0 1.5 0v-6.5h6.5a.75.75 0 0 0 0-1.5h-6.5v-6.5Z"/></svg>
                                    </span>
                                    {{ __('setup.first_machine_optional') }}
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-slate-400 transition {{ $machine_section_open ? 'rotate-180' : '' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                            </button>
                            @if ($machine_section_open)
                            <div id="first-machine-fields" class="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-3 dark:border-slate-700">
                                <x-form-input :label="__('setup.machine_name')" name="machine_name" wire:model="machine_name" />
                                <x-form-input :label="__('setup.machine_code')" name="machine_code" wire:model="machine_code" />
                                <x-form-input :label="__('setup.daily_capacity')" name="machine_daily_capacity" type="number" min="0.0001" step="0.0001" wire:model="machine_daily_capacity" />
                            </div>
                            @endif
                        </div>

                        <div class="mt-6 rounded-2xl border border-slate-200 p-4 dark:border-slate-700 sm:p-5">
                            <h2 class="font-black">{{ __('setup.curing_defaults_title') }}</h2>
                            <div class="mt-4 grid min-w-0 gap-4 md:grid-cols-2">
                                <div>
                                    <x-form-input :label="__('setup.earliest_selling_day')" name="default_sellable_after_days" type="number" min="1" wire:model="default_sellable_after_days" />
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('setup.earliest_selling_day_help') }}</p>
                                </div>
                                <div>
                                    <x-form-input :label="__('setup.full_curing_days')" name="default_curing_days" type="number" min="1" wire:model="default_curing_days" />
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('setup.full_curing_days_help') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 rounded-2xl border border-cyan-200 bg-cyan-50/70 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10 sm:p-5">
                            <label class="flex cursor-pointer items-start gap-3" for="quality_control_preference">
                                <input id="quality_control_preference" type="checkbox" wire:model="quality_control_preference" class="mt-1 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                                <span>
                                    <span class="block font-black text-slate-950 dark:text-white">{{ __('setup.quality_control') }}</span>
                                    <span class="mt-1 block text-sm leading-6 text-slate-600 dark:text-slate-300">{{ __('setup.quality_description') }}</span>
                                    <span class="mt-2 block text-xs font-semibold leading-5 text-cyan-800 dark:text-cyan-200">{{ __('setup.quality_help') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                @else
                    <div>
                        <h1 class="text-2xl font-black">Review & Complete</h1>
                        <p class="mt-1 text-sm text-slate-500">Confirm the setup summary before initializing the ERP.</p>
                        <div class="mt-6 grid gap-4 lg:grid-cols-3">
                            <x-card title="Company Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Company</dt><dd>{{ $company_name }}</dd><dt class="font-black">Business Type</dt><dd>{{ $business_type }}</dd><dt class="font-black">Phone</dt><dd>{{ $phone }}</dd><dt class="font-black">WhatsApp</dt><dd>{{ $whatsapp_number }}</dd><dt class="font-black">Location</dt><dd>{{ trim($district.', '.$region.', '.$country, ', ') }}</dd><dt class="font-black">Inventory Stock Mode</dt><dd>{{ $inventory_stock_mode === 'warehouse' ? 'Nina Ghala Kuu' : 'Sina Ghala Kuu' }}</dd></dl>
                            </x-card>
                            <x-card title="Super Admin Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Name</dt><dd>{{ $admin_name }}</dd><dt class="font-black">Email</dt><dd>{{ $admin_email }}</dd><dt class="font-black">Phone</dt><dd>{{ $admin_phone }}</dd><dt class="font-black">Role</dt><dd>Super Admin</dd></dl>
                            </x-card>
                            <x-card title="Branch Information">
                                <dl class="space-y-2 text-sm"><dt class="font-black">Branch</dt><dd>{{ $branch_name }}</dd><dt class="font-black">Code</dt><dd>{{ $branch_code }}</dd><dt class="font-black">Manager</dt><dd>{{ $branch_manager_name ?: $admin_name }}</dd><dt class="font-black">Default</dt><dd>{{ $branch_is_default ? 'Yes' : 'No' }}</dd></dl>
                            </x-card>
                            @unless ($manufacturing_enabled)
                                <x-card :title="__('setup.business_operations')"><dl class="space-y-2 text-sm"><dt class="font-black">{{ __('setup.manufacturing_enabled') }}</dt><dd>{{ __('setup.no') }}</dd></dl></x-card>
                            @endunless
                            @if ($manufacturing_enabled)
                                <x-card :title="__('setup.production_setup')">
                                    <dl class="space-y-2 text-sm"><dt class="font-black">{{ __('setup.manufacturing_enabled') }}</dt><dd>{{ __('setup.yes') }}</dd><dt class="font-black">{{ __('setup.production_branch') }}</dt><dd>{{ $branch_name }}</dd><dt class="font-black">{{ __('setup.production_locations') }}</dt><dd>{{ $raw_materials_store_name }}, {{ $production_area_name }}, {{ $curing_yard_name }}, {{ $finished_goods_store_name }}</dd>@if($machine_section_open && filled($machine_name))<dt class="font-black">{{ __('setup.first_machine_optional') }}</dt><dd>{{ $machine_name }}{{ filled($machine_code) ? ' · '.$machine_code : '' }}</dd>@endif<dt class="font-black">{{ __('setup.curing_defaults') }}</dt><dd>{{ $default_sellable_after_days }} / {{ $default_curing_days }} {{ __('setup.days') }}</dd><dt class="font-black">{{ __('setup.quality_control') }}</dt><dd>{{ $quality_control_preference ? __('setup.yes') : __('setup.no') }}</dd></dl>
                                </x-card>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 dark:border-slate-800 sm:flex-row sm:justify-between">
                    <button type="button" wire:click="back" @disabled($step === 1) class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-black disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700">Back</button>
                    @if ($step !== 5)
                        <button type="button" wire:click="next" class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20" wire:loading.attr="disabled">Next</button>
                    @else
                        <button type="button" wire:click="complete" class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20" wire:loading.attr="disabled">
                            <span wire:loading.remove>Complete Setup</span>
                            <span wire:loading>Completing...</span>
                        </button>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
