<?php

namespace Database\Factories;

use App\Enums\FinanceDocumentStatus;
use App\Models\JournalVoucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalVoucher>
 */
class JournalVoucherFactory extends Factory
{
    protected $model = JournalVoucher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'voucher_no' => 'JV-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'voucher_date' => now()->toDateString(),
            'narration' => fake()->sentence(),
            'status' => FinanceDocumentStatus::Draft,
            'posted_at' => null,
            'created_by' => null,
        ];
    }
}
