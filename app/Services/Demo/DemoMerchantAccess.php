<?php

namespace App\Services\Demo;

use App\Models\Merchant;
use App\Models\Permission;
use App\Models\PermissionModule;
use App\Models\Role;
use Database\Seeders\PermissionsModulesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DemoMerchantAccess
{
    public function grantFullAccess(Merchant $merchant): void
    {
        $this->ensurePermissionModulesExist();
        $this->syncAllPermissionModules($merchant);
        $this->assignAdminRoleWithAllPermissions($merchant);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensurePermissionModulesExist(): void
    {
        (new PermissionsModulesSeeder)->run();
    }

    private function syncAllPermissionModules(Merchant $merchant): void
    {
        $moduleIds = PermissionModule::query()->pluck('id');

        if ($moduleIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($moduleIds as $moduleId) {
            $exists = DB::table('merchant_permission_modules')
                ->where('merchant_id', $merchant->id)
                ->where('permission_module_id', $moduleId)
                ->exists();

            if ($exists) {
                continue;
            }

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'permission_module_id' => $moduleId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('merchant_permission_modules')->insert($rows);
        }
    }

    private function assignAdminRoleWithAllPermissions(Merchant $merchant): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'merchant'],
            ['id' => Str::uuid()->toString()]
        );

        $permissionNames = Permission::query()
            ->where('guard_name', 'merchant')
            ->pluck('name');

        if ($permissionNames->isNotEmpty()) {
            $role->syncPermissions($permissionNames);
            $merchant->syncPermissions($permissionNames);
        }

        $merchant->syncRoles([$role]);
    }
}
