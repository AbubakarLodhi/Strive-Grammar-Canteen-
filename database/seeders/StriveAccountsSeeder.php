<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use App\Services\Demo\DemoMerchantAccess;
use App\Services\Finance\FinanceLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StriveAccountsSeeder extends Seeder
{
    private const PASSWORD = 'canteen@123';

    public function run(): void
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['email' => 'info@strive.com'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Strive Uniform and Bookshop',
                'phone' => null,
                'address_line_1' => 'Pakistan',
                'city' => 'Karachi',
                'website' => null,
                'status' => Merchant::STATUS_VERIFIED,
                'is_active' => true,
                'password' => self::PASSWORD,
            ]
        );

        $merchant->forceFill([
            'name' => 'Strive Uniform and Bookshop',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => self::PASSWORD,
        ])->save();

        app(DemoMerchantAccess::class)->grantFullAccess($merchant);
        app(FinanceLedger::class)->provisionDefaultAccounts($merchant);

        $staff = User::query()->firstOrCreate(
            ['email' => 'admin@strive.com'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Strive Admin',
                'merchant_id' => $merchant->id,
                'password' => self::PASSWORD,
                'status' => User::STATUS_VERIFIED,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $staff->forceFill([
            'name' => 'Strive Admin',
            'merchant_id' => $merchant->id,
            'password' => self::PASSWORD,
            'status' => User::STATUS_VERIFIED,
            'is_active' => true,
            'email_verified_at' => $staff->email_verified_at ?? now(),
        ])->save();

        $role = Role::query()
            ->where('name', 'Admin')
            ->where('guard_name', 'merchant')
            ->first();

        if ($role) {
            $staff->syncRoles([$role]);
        }

        $this->command?->info('Merchant → /merchant/login  info@strive.com');
        $this->command?->info('Staff → /staff/login  admin@strive.com');
    }
}
