<?php

namespace App\Models;

use App\Enums\CashVoucherDirection;
use App\Enums\FinanceDocumentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class CashVoucher extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'direction',
        'voucher_no',
        'voucher_date',
        'cash_account_id',
        'counter_account_id',
        'amount',
        'reference_no',
        'notes',
        'status',
        'journal_voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => CashVoucherDirection::class,
            'voucher_date' => 'date',
            'amount' => 'decimal:2',
            'status' => FinanceDocumentStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'cash_account_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'counter_account_id');
    }

    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    public function isPosted(): bool
    {
        return $this->status === FinanceDocumentStatus::Posted;
    }

    protected static function booted(): void
    {
        static::deleting(function (CashVoucher $voucher): void {
            if ($voucher->isPosted()) {
                throw new \RuntimeException('Posted cash vouchers cannot be deleted.');
            }
        });
    }
}
