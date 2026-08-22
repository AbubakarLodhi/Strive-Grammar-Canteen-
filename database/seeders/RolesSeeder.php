<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $rolesPermissions = [

            // 🔴 PLATFORM LEVEL
            'Super Admin' => [
                '*',
            ],

            // 🟠 MERCHANT SUPER ADMIN — full access to all merchant-guard permissions
            'Admin' => [
                '*',
            ],

            // 🟡 SUPPORT
            'Support Admin' => [
                'dashboard.view',
                'orders.view',
                'orders.update',
                'customers.view',
                'vendors.view',
                'notification_templates.view',
                'invoice_templates.view',
                'audits.view',
                'products.view',
                'reports.view',
                'merchants.view',
                'merchants.update',
                'merchant_settings.view',
                'merchant_settings.update',
            ],

            // 🔵 OPERATIONS
            'Supervisor' => [
                'orders.view',
                'orders.create',
                'orders.update',
                'sales.view',
                'sales.create',
                'purchases.view',
                'purchases.create',
                'expenses.view',
                'expenses.create',
                'assets.view',
                'assets.create',
                'asset_types.view',
                'asset_types.create',
                'customers.view',
                'vendors.view',
                'products.view',
            ],

            // 🟢 FINANCE
            'Finance Admin' => [
                'reports.view',
                'sales.view',
                'purchases.view',
                'expenses.view',
                'assets.view',
                'asset_types.view',
                'payrolls.view',
                'orders.view',
                'ledger_accounts.*',
                'journal_vouchers.*',
                'bank_deposits.*',
                'finance_ledger.view',
            ],

            // ⚪ DATA ENTRY
            'Data Entry' => [
                'products.create',
                'products.update',
                'customers.create',
                'vendors.create',
                'orders.create',
            ],
        ];

        /**
         * Only seed roles for these guards
         */
        $allowedGuards = ['merchant'];

        foreach ($rolesPermissions as $roleName => $permissions) {

            foreach ($allowedGuards as $guard) {

                /**
                 * ❌ Skip Super Admin for merchant guard
                 */
                if ($guard === 'merchant' && $roleName === 'Super Admin') {
                    continue;
                }

                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => $guard,
                ]);

                $finalPermissions = collect($permissions)->flatMap(function ($permission) use ($guard) {

                    // Super Admin → all permissions of the guard
                    if ($permission === '*') {
                        return Permission::where('guard_name', $guard)->pluck('name');
                    }

                    // Module wildcard (e.g. orders.*)
                    if (str_ends_with($permission, '.*')) {
                        $module = str_replace('.*', '', $permission);

                        return Permission::where('guard_name', $guard)
                            ->where('name', 'like', $module.'.%')
                            ->pluck('name');
                    }

                    // Single permission
                    return [$permission];
                })
                    ->unique()
                    ->values();

                $role->syncPermissions($finalPermissions);
            }
        }
    }
}
