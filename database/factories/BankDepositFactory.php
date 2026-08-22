<?php

namespace Database\Factories;

use App\Enums\FinanceDocumentStatus;
use App\Models\BankDeposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankDeposit>
 */
class BankDepositFactory extends Factory
{
    protected $model = BankDeposit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deposit_no' => 'BD-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'deposit_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'reference_no' => fake()->optional()->bothify('REF-####'),
            'notes' => null,
            'status' => FinanceDocumentStatus::Draft,
            'journal_voucher_id' => null,
            'created_by' => null,
        ];
    }
}
