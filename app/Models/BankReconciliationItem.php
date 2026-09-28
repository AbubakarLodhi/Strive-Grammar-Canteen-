<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'bank_reconciliation_id',
        'journal_voucher_line_id',
        'is_matched',
        'statement_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_matched' => 'boolean',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journalVoucherLine(): BelongsTo
    {
        return $this->belongsTo(JournalVoucherLine::class);
    }
}
