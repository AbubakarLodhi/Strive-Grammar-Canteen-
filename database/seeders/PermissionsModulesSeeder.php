<?php

namespace Database\Seeders;

use App\Models\PermissionModule;
use Illuminate\Database\Seeder;

class PermissionsModulesSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'dashboard' => 'Dashboard',
            'users' => 'Users',
            'settings' => 'Settings',
            'roles_permissions' => 'Roles & Permissions',
            'merchants' => 'Merchants',
            'merchant_settings' => 'Merchant Settings',
            'businesses' => 'Businesses',
            'orders' => 'Orders',
            'branches' => 'Branches',
            'customers' => 'Customers',
            'vendors' => 'Vendors',
            'notification_templates' => 'Notification Templates',
            'categories' => 'Categories',
            'sub_categories' => 'Sub Categories',
            'brands' => 'Brands',
            'models' => 'Models',
            'products' => 'Products',
            'products_variants' => 'Products Variants',
            'addons' => 'Addons',
            'sales' => 'Sales',
            'purchases' => 'Purchases',
            'expenses' => 'Expenses',
            'ledger_accounts' => 'Parties',
            'journal_vouchers' => 'Journal Vouchers',
            'bank_deposits' => 'Bank Deposits',
            'cheque_books' => 'Cheque Books',
            'bank_cheques' => 'Bank Cheques',
            'bank_reconciliations' => 'Bank Reconciliation',
            'cash_vouchers' => 'Cash Vouchers',
            'online_transfers' => 'Online Transfers',
            'cash_book' => 'Cash Book',
            'bank_statements' => 'Bank Statements',
            'bank_position' => 'Bank & Cash Position',
            'receivable_aging' => 'Receivable Aging',
            'expense_reports' => 'Expense Reports',
            'finance_ledger' => 'General Ledger',
            'cash_flows' => 'Cash Flows',
            'asset_types' => 'Asset Types',
            'assets' => 'Assets',
            'audits' => 'Audits',
            'reports' => 'Reports',
            'payrolls' => 'Payrolls',
            'invoice_templates' => 'Invoice Templates',
        ];

        foreach ($modules as $key => $label) {
            PermissionModule::updateOrCreate(
                ['module' => $key],
                [
                    'label' => $label,
                ]
            );
        }
    }
}
