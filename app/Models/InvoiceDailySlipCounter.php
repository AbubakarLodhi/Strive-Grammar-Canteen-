<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDailySlipCounter extends Model
{
    /** @var string[] */
    protected $fillable = [
        'merchant_id',
        'counter_date',
        'current_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counter_date' => 'date',
            'current_count' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
