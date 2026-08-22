<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            'dashboard' => ['view', 'create', 'update', 'delete'],
            'users' => ['view', 'create', 'update', 'delete'],
            'settings' => ['view', 'create', 'update', 'delete'],
            'roles_permissions' => ['view', 'create', 'update', 'delete'],
            'merchants' => ['view', 'create', 'update', 'delete'],
            'merchant_settings' => ['view', 'create', 'update', 'delete'],
            'orders' => ['view', 'create', 'update', 'delete'],
            'businesses' => ['view', 'create', 'update', 'delete'],
            'branches' => ['view', 'create', 'update', 'delete'],
            'customers' => ['view', 'create', 'update', 'delete'],
            'vendors' => ['view', 'create', 'update', 'delete'],
            'notification_templates' => ['view', 'create', 'update', 'delete'],
            'reports' => ['view', 'create', 'update', 'delete'],
            'categories' => ['view', 'create', 'update', 'delete'],
            'sub_categories' => ['view', 'create', 'update', 'delete'],
            'brands' => ['view', 'create', 'update', 'delete'],
            'models' => ['view', 'create', 'update', 'delete'],
            'products' => ['view', 'create', 'update', 'delete'],
            'products_variants' => ['view', 'create', 'update', 'delete'],
            'addons' => ['view', 'create', 'update', 'delete'],
            'sales' => ['view', 'create', 'update', 'delete'],
            'purchases' => ['view', 'create', 'update', 'delete'],
            'expenses' => ['view', 'create', 'update', 'delete'],
            'ledger_accounts' => ['view', 'create', 'update', 'delete'],
            'journal_vouchers' => ['view', 'create', 'update', 'delete'],
            'bank_deposits' => ['view', 'create', 'update', 'delete'],
            'finance_ledger' => ['view', 'create', 'update', 'delete'],
            'cash_flows' => ['view', 'create', 'update', 'delete'],
            'asset_types' => ['view', 'create', 'update', 'delete'],
            'assets' => ['view', 'create', 'update', 'delete'],
            'audits' => ['view', 'create', 'update', 'delete'],
            'payrolls' => ['view', 'create', 'update', 'delete'],
            'invoice_templates' => ['view', 'create', 'update', 'delete'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "$module.$action", 'guard_name' => 'staff']);
                Permission::firstOrCreate(['name' => "$module.$action", 'guard_name' => 'merchant']);
            }
        }
    }
}
