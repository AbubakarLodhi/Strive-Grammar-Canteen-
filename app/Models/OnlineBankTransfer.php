<?php

namespace App\Models;

use App\Enums\FinanceDocumentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class OnlineBankTransfer extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'merchant_id',
        'transfer_no',
        'transfer_date',
        'from_account_id',
        'to_account_id',
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
            'transfer_date' => 'date',
            'amount' => 'decimal:2',
            'status' => FinanceDocumentStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'to_account_id');
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
        static::deleting(function (OnlineBankTransfer $transfer): void {
            if ($transfer->isPosted()) {
                throw new \RuntimeException('Posted online bank transfers cannot be deleted.');
            }
        });
    }
}
