<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\Permission;
use App\Models\PermissionModule;
use App\Models\Role;
use App\Services\Demo\DemoMerchantAccess;
use Database\Seeders\PermissionsModulesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class FinanceEnableModulesCommand extends Command
{
    protected $signature = 'finance:enable-modules
                            {--merchant= : Merchant email or id; omit to enable for all merchants}
                            {--all-modules : Attach every permission module, not only finance}';

    protected $description = 'Seed finance permissions/modules and attach them so Cash Book, Cheques, Aging, etc. appear in the nav';

    /** @var list<string> */
    private array $financeModules = [
        'ledger_accounts',
        'journal_vouchers',
        'bank_deposits',
        'cheque_books',
        'bank_cheques',
        'bank_reconciliations',
        'cash_vouchers',
        'online_transfers',
        'cash_book',
        'bank_statements',
        'bank_position',
        'receivable_aging',
        'expense_reports',
        'finance_ledger',
        'cash_flows',
        'expenses',
        'reports',
        'sales',
        'purchases',
        'payrolls',
    ];

    public function handle(DemoMerchantAccess $demoMerchantAccess): int
    {
        $this->components->info('Seeding permissions catalog…');
        (new PermissionsSeeder)->run();
        (new PermissionsModulesSeeder)->run();
        (new RolesSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $merchantOpt = $this->option('merchant');
        $merchants = Merchant::query()
            ->when(
                $merchantOpt,
                fn ($q) => $q->where('email', $merchantOpt)->orWhere('id', $merchantOpt)
            )
            ->get();

        if ($merchants->isEmpty()) {
            $this->warn('No merchants matched.');

            return self::FAILURE;
        }

        foreach ($merchants as $merchant) {
            if ($this->option('all-modules')) {
                $demoMerchantAccess->grantFullAccess($merchant);
                $this->components->twoColumnDetail($merchant->email ?? $merchant->name, 'full access');

                continue;
            }

            $this->attachFinanceModules($merchant);
            $this->grantFinancePermissions($merchant);
            $this->components->twoColumnDetail($merchant->email ?? $merchant->name, 'finance modules enabled');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->components->success('Done. Ask users to refresh the panel.');

        return self::SUCCESS;
    }

    private function attachFinanceModules(Merchant $merchant): void
    {
        $moduleIds = PermissionModule::query()
            ->whereIn('module', $this->financeModules)
            ->pluck('id');

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
                'id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'permission_module_id' => $moduleId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('merchant_permission_modules')->insert($rows);
        }

        PermissionModule::forgetMerchantCache($merchant->id);
    }

    private function grantFinancePermissions(Merchant $merchant): void
    {
        $names = Permission::query()
            ->where('guard_name', 'merchant')
            ->where(function ($q): void {
                foreach ($this->financeModules as $module) {
                    $q->orWhere('name', 'like', $module.'.%');
                }
            })
            ->pluck('name');

        if ($names->isEmpty()) {
            return;
        }

        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'merchant']
        );
        $role->givePermissionTo($names->all());
        $merchant->givePermissionTo($names->all());
        $merchant->assignRole($role);
    }
}
