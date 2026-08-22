<?php

namespace Database\Factories;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numerify('6###'),
            'name' => fake()->words(2, true),
            'type' => LedgerAccountType::Asset,
            'is_bank' => false,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => 0,
            'account_number' => null,
        ];
    }

    public function bank(): static
    {
        return $this->state(fn (): array => [
            'name' => 'UBL',
            'code' => '1010',
            'is_bank' => true,
            'type' => LedgerAccountType::Asset,
            'account_number' => fake()->numerify('##########'),
        ]);
    }
}
