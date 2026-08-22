<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds system catalog only: permissions, roles, countries, cities, and default templates.
 * Does not create merchants, staff, or business data.
 */
class CleanStartSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RolesSeeder::class,
            CountriesSeeder::class,
            CitiesSeeder::class,
            PermissionsModulesSeeder::class,
            CreditPaymentReminderNotificationTemplateSeeder::class,
            StriveAccountsSeeder::class,
        ]);

        $this->command?->info('');
        $this->command?->info('Clean start complete.');
        $this->command?->info('Merchant login → /merchant/login');
        $this->command?->info('Staff login → /staff/login');
    }
}
